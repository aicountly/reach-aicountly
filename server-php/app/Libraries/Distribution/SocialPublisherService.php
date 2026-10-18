<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\ProviderNotConfiguredException;
use App\Libraries\Distribution\Providers\ProviderReceipt;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Distribution\Providers\SocialPublisherInterface;
use App\Models\Distribution\CampaignDispatchModel;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\CampaignOperationalMetricsModel;

/**
 * Publishes reach_social_posts (and campaign social variants) through the
 * configured social publisher.
 *
 * Honest states on reach_social_posts:
 *   publishing  the provider accepted the post for background delivery
 *               (social-api.ai 201); SocialPostStatusPoller confirms it
 *   posted      the provider reported the post published (a synchronous
 *               provider's receipt, or the poller's GET /posts/{id})
 *   failed      refused, failed, or not confirmed in time — with the reason
 *               and the provider's error code
 */
class SocialPublisherService
{
    /** A `sending` attempt older than this had its worker die; its outcome is unknown. */
    public const ABANDONED_SENDING_SECS = 900;

    private SocialPublisherInterface $publisher;

    public function __construct(
        private readonly CampaignDispatchModel          $dispatchModel,
        private readonly CampaignDeliveryAttemptModel   $attemptModel,
        private readonly CampaignOperationalMetricsModel $metricsModel,
        private readonly AuditLogger                     $audit,
    ) {
        $this->publisher = ChannelProviderFactory::makeSocialPublisher();
    }

    /** @internal tests / alternative wiring */
    public function setPublisher(SocialPublisherInterface $publisher): void
    {
        $this->publisher = $publisher;
    }

    public function providerName(): string
    {
        return $this->publisher->providerName();
    }

    /**
     * Dispatch a social post via provider.
     *
     * Replaces the manual markPosted() shortcut with a governed, provider-backed dispatch.
     */
    public function dispatch(int $postId, int $tenantId, ?int $actorId): array
    {
        $db   = \Config\Database::connect();
        $post = $db->table('reach_social_posts')->where('id', $postId)->get()->getRowArray();

        if ($post === null || (int) ($post['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('Social post not found.', 404);
        }

        if ($post['approval_status'] !== 'approved') {
            throw new \RuntimeException('Post must be approved before dispatch.', 409);
        }

        // Already with the provider (in flight or published): never send twice.
        if (in_array($post['status'] ?? '', ['publishing', 'posted'], true)) {
            return ['status' => 'already_dispatched', 'post_status' => $post['status'], 'provider_post_id' => $post['external_post_id'] ?? null];
        }

        $idempotencyKey = 'social-dispatch:' . $postId . ':' . ($post['uuid'] ?? $postId);

        // Idempotency via delivery attempts: a post that was accepted (or is in
        // flight) is not sent again; a failed attempt may be retried.
        $latest = $this->attemptModel->like('idempotency_key', $idempotencyKey, 'after')->orderBy('id', 'DESC')->first();
        if ($latest !== null) {
            $abandoned = ($latest['status'] ?? '') === 'sending'
                && strtotime((string) ($latest['created_at'] ?? '')) < time() - self::ABANDONED_SENDING_SECS;
            if ($abandoned) {
                // A worker died mid-send (e.g. during a long media upload): record
                // that the outcome is unknown and allow a new attempt. If that
                // attempt had created the post, social-api.ai refuses the repeat
                // as a duplicate (409) rather than posting twice.
                $this->attemptModel->update((int) $latest['id'], [
                    'status' => 'failed', 'failure_class' => 'unknown', 'failed_at' => date('Y-m-d H:i:s'),
                    'failure_detail' => 'No outcome recorded within ' . (self::ABANDONED_SENDING_SECS / 60) . ' minutes (worker stopped?); retried.',
                ]);
            } elseif (in_array($latest['status'] ?? '', ['accepted', 'sent', 'delivered', 'sending'], true)) {
                return ['status' => 'already_dispatched', 'attempt' => $latest];
            }
            $idempotencyKey .= ':retry:' . bin2hex(random_bytes(4));
        }

        $platform = $post['channel'] ?? 'linkedin';

        $message = new ChannelMessage(
            idempotencyKey:   $idempotencyKey,
            recipientAddress: (string) ($post['destination_id'] ?? 'default-page'),
            content:          (string) $post['content'],
            mediaRefs:        self::jsonList($post['media_refs'] ?? null),
            metadata:         [
                'platform'     => $platform,
                'hashtags'     => $post['hashtags'] ?? '[]',
                'title'        => $post['title'] ?? null,
                'scheduled_at' => $post['scheduled_at'] ?? null,
            ],
        );

        $attemptId = $this->attemptModel->insert([
            'dispatch_id'    => null,
            'attempt_number' => 1,
            'status'         => 'sending',
            'provider'       => $this->publisher->providerName(),
            'idempotency_key'=> $idempotencyKey,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        try {
            $receipt = $this->publisher->publish($message, $platform);
            if (! $receipt->isAccepted() && ! $receipt->isSubmitted()) {
                throw new \RuntimeException('Social provider did not accept the post (status ' . $receipt->status . ').', 502);
            }

            $this->attemptModel->update($attemptId, [
                'status'              => 'accepted',
                'provider_message_id' => $receipt->providerMessageId,
                'remote_url'          => $receipt->remoteUrl,
                'accepted_at'         => date('Y-m-d H:i:s'),
            ]);

            if ($receipt->isSubmitted()) {
                $this->recordSubmitted($postId, $receipt);
                $this->audit->record(AuditLogger::DISTRIBUTION_SOCIAL_SUBMITTED, [
                    'post_id'          => $postId,
                    'provider'         => $this->publisher->providerName(),
                    'provider_post_id' => $receipt->providerMessageId,
                    'platform'         => $platform,
                ], $actorId);

                return ['status' => 'submitted', 'post_status' => 'publishing', 'receipt' => [
                    'provider_message_id' => $receipt->providerMessageId,
                    'status'              => $receipt->status,
                    'note'                => 'Accepted for delivery; the post is marked posted once the provider reports it published.',
                ]];
            }

            $db->table('reach_social_posts')->where('id', $postId)->update([
                'status'         => 'posted',
                'published_at'   => date('Y-m-d H:i:s'),
                'external_post_id' => $receipt->providerMessageId,
                'remote_post_id' => $receipt->providerMessageId,
                'remote_url'     => $receipt->remoteUrl,
                'provider'       => $this->publisher->providerName(),
                'error_message'  => null,
            ]);

            $this->audit->record(AuditLogger::DISTRIBUTION_SOCIAL_POSTED, [
                'post_id'           => $postId,
                'provider_message_id' => $receipt->providerMessageId,
                'platform'          => $platform,
            ], $actorId);

            return ['status' => 'dispatched', 'receipt' => [
                'provider_message_id' => $receipt->providerMessageId,
                'remote_url'          => $receipt->remoteUrl,
                'status'              => $receipt->status,
            ]];
        } catch (\Throwable $e) {
            $class = self::failureClass($e);
            $this->attemptModel->update($attemptId, [
                'status'        => 'failed',
                'failure_class' => $class,
                'failure_detail'=> mb_substr($e->getMessage(), 0, 2000),
                'failed_at'     => date('Y-m-d H:i:s'),
            ]);

            // Honest outcome: never `posted`. The error tells the operator why.
            $db->table('reach_social_posts')->where('id', $postId)->update(self::failureColumns($e) + [
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'provider'      => $this->publisher->providerName(),
            ]);

            $this->audit->record(AuditLogger::DISTRIBUTION_SOCIAL_FAILED, [
                'post_id'    => $postId,
                'error'      => $e->getMessage(),
                'error_code' => self::providerError($e)?->errorCode,
            ], $actorId);

            throw $e;
        }
    }

    /**
     * Publish a campaign's social channel variant (fan-out path). The dispatch
     * row ends `completed` only when the provider reports the post published:
     * at once for a synchronous provider; for social-api.ai the post is kept as
     * a reach_social_posts row (`publishing`, linked by dispatch_id) and
     * SocialPostStatusPoller settles the dispatch.
     */
    public function dispatchVariant(int $dispatchId, int $variantId, int $tenantId): array
    {
        $db       = \Config\Database::connect();
        $dispatch = $db->table('reach_campaign_dispatches')->where('id', $dispatchId)->get()->getRowArray();
        if ($dispatch === null || (int) $dispatch['tenant_id'] !== $tenantId) {
            throw new \RuntimeException('Dispatch not found.', 404);
        }
        if (!in_array($dispatch['status'], ['queued', 'dispatching'], true)) {
            return ['status' => 'noop', 'dispatch_status' => $dispatch['status']];
        }
        // Already submitted to an asynchronous provider: the poller settles it.
        $inFlight = $db->table('reach_social_posts')->where('dispatch_id', $dispatchId)->where('status', 'publishing')->countAllResults();
        if ($inFlight > 0) {
            return ['status' => 'submitted', 'dispatch_id' => $dispatchId, 'note' => 'Already with the provider; waiting for it to report the post published.'];
        }
        $variant = $db->table('reach_campaign_channel_variants')->where('id', $variantId)->get()->getRowArray();
        if ($variant === null) {
            throw new \RuntimeException('Channel variant not found.', 404);
        }
        $content  = is_array($variant['content_json']) ? $variant['content_json'] : (json_decode((string) ($variant['content_json'] ?? '{}'), true) ?: []);
        $platform = (string) ($content['platform'] ?? 'linkedin');
        $key      = 'd' . $dispatchId . ':social:' . $variantId;
        $now      = date('Y-m-d H:i:s');
        $text     = (string) ($content['text'] ?? $content['content'] ?? $content['body'] ?? '');
        $media    = (array) ($content['media_refs'] ?? []);

        $db->table('reach_campaign_dispatches')->where('id', $dispatchId)->update(['status' => 'dispatching', 'started_at' => $now, 'updated_at' => $now]);
        $attemptId = $this->attemptModel->insert([
            'dispatch_id' => $dispatchId, 'attempt_number' => 1, 'status' => 'sending',
            'provider' => $this->publisher->providerName(), 'idempotency_key' => $key, 'created_at' => $now,
        ]);

        $status = 'failed';
        $error  = null;
        try {
            $receipt = $this->publisher->publish(new ChannelMessage(
                idempotencyKey:   $key,
                recipientAddress: (string) ($content['destination_id'] ?? 'default-page'),
                content:          $text,
                mediaRefs:        $media,
                metadata:         ['platform' => $platform, 'title' => $content['title'] ?? null, 'hashtags' => $content['hashtags'] ?? null],
            ), $platform);
            if (! $receipt->isAccepted() && ! $receipt->isSubmitted()) {
                throw new \RuntimeException('Social provider did not accept the post (status ' . $receipt->status . ').', 502);
            }
            $this->attemptModel->update($attemptId, [
                'status' => 'accepted', 'provider_message_id' => $receipt->providerMessageId,
                'remote_url' => $receipt->remoteUrl, 'accepted_at' => date('Y-m-d H:i:s'),
            ]);

            if ($receipt->isSubmitted()) {
                $postId = $this->insertVariantPost($dispatch, $platform, $text, $media, $content, $tenantId);
                $this->recordSubmitted($postId, $receipt);
                $db->table('reach_campaign_dispatches')->where('id', $dispatchId)->update(['updated_at' => date('Y-m-d H:i:s'), 'last_error' => null]);
                $this->audit->record(AuditLogger::DISTRIBUTION_SOCIAL_SUBMITTED, [
                    'dispatch_id' => $dispatchId, 'variant_id' => $variantId, 'post_id' => $postId, 'platform' => $platform,
                    'provider_post_id' => $receipt->providerMessageId,
                ], null);

                return ['status' => 'submitted', 'dispatch_id' => $dispatchId, 'post_id' => $postId, 'error' => null];
            }
            $status = 'completed';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->attemptModel->update($attemptId, [
                'status' => 'failed',
                'failure_class' => self::failureClass($e),
                'failure_detail' => mb_substr($error, 0, 2000), 'failed_at' => date('Y-m-d H:i:s'),
            ]);
        }

        self::settleDispatch($dispatchId, $status === 'completed', $error, $this->audit);
        $this->audit->record($status === 'completed' ? AuditLogger::DISTRIBUTION_SOCIAL_POSTED : AuditLogger::DISTRIBUTION_SOCIAL_FAILED, [
            'dispatch_id' => $dispatchId, 'variant_id' => $variantId, 'platform' => $platform, 'error' => $error,
        ], null);

        return ['status' => $status, 'dispatch_id' => $dispatchId, 'error' => $error];
    }

    public function getStatus(int $postId): array
    {
        $db   = \Config\Database::connect();
        $post = $db->table('reach_social_posts')->where('id', $postId)->get()->getRowArray();
        if ($post === null) {
            return ['status' => 'not_found'];
        }
        if (($post['status'] ?? '') === 'publishing') {
            // Same path as the scheduled poll, so the row is updated honestly.
            $poll = (new SocialPostStatusPoller($this->publisher))->pollOne($postId, true);
            $post = $db->table('reach_social_posts')->where('id', $postId)->get()->getRowArray() ?? $post;
            return ['status' => $post['status'], 'provider_status' => $post['provider_status'] ?? null, 'error' => $post['error_message'] ?? null, 'poll' => $poll];
        }
        if (!empty($post['external_post_id']) && ($post['provider'] ?? '') !== 'social_api') {
            try {
                $status = $this->publisher->getStatus((string) $post['external_post_id']);
                return ['status' => $status->normalisedStatus, 'provider_status' => $status];
            } catch (\Throwable) {
                // Provider unavailable — return cached status
            }
        }
        return ['status' => $post['status'] ?? 'unknown', 'provider_status' => $post['provider_status'] ?? null, 'error' => $post['error_message'] ?? null];
    }

    // ── shared with SocialPostStatusPoller ─────────────────────────────────

    /**
     * Close a campaign social dispatch and let its campaign follow.
     */
    public static function settleDispatch(int $dispatchId, bool $ok, ?string $error, ?AuditLogger $audit = null): void
    {
        $db       = \Config\Database::connect();
        $dispatch = $db->table('reach_campaign_dispatches')->where('id', $dispatchId)->get()->getRowArray();
        if ($dispatch === null) {
            return;
        }
        $db->table('reach_campaign_dispatches')->where('id', $dispatchId)->update([
            'status'       => $ok ? 'completed' : 'failed',
            'sent_count'   => $ok ? 1 : 0,
            'failed_count' => $ok ? 0 : 1,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'last_error'   => $ok ? null : $error,
        ]);
        (new ChannelDispatchService(null, null, $audit ?? new AuditLogger()))->settleParentCampaign((int) $dispatch['campaign_id']);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function recordSubmitted(int $postId, ProviderReceipt $receipt): void
    {
        \Config\Database::connect()->table('reach_social_posts')->where('id', $postId)->update([
            'status'                 => 'publishing',
            'external_post_id'       => $receipt->providerMessageId,
            'remote_post_id'         => null,
            'remote_url'             => null,
            'provider'               => $this->publisher->providerName(),
            'platform_account_id'    => $receipt->meta['account_id'] ?? null,
            'provider_status'        => 'submitted',
            'provider_error_code'    => null,
            'provider_response_json' => json_encode(['create' => $receipt->meta['response'] ?? null, 'http_status' => $receipt->meta['http_status'] ?? null, 'publish_mode' => $receipt->meta['publish_mode'] ?? null, 'scheduled_at' => $receipt->meta['scheduled_at'] ?? null, 'media_ids' => $receipt->meta['media_ids'] ?? [], 'duplicate_of_existing' => $receipt->meta['duplicate_of_existing'] ?? false], JSON_UNESCAPED_SLASHES),
            'submitted_at'           => date('Y-m-d H:i:s'),
            'last_polled_at'         => null,
            'poll_count'             => 0,
            'error_message'          => null,
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $dispatch
     * @param array<string, mixed> $content
     */
    private function insertVariantPost(array $dispatch, string $platform, string $text, array $media, array $content, int $tenantId): int
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('reach_social_posts')->insert([
            'campaign_id'     => $dispatch['campaign_id'] ?? null,
            'channel'         => $platform === 'x' ? 'twitter' : $platform,
            'title'           => isset($content['title']) ? mb_substr((string) $content['title'], 0, 255) : null,
            'content'         => $text,
            'media_refs'      => json_encode(array_values($media), JSON_UNESCAPED_SLASHES),
            'hashtags'        => json_encode(array_values((array) ($content['hashtags'] ?? [])), JSON_UNESCAPED_UNICODE),
            'status'          => 'publishing',
            'approval_status' => 'approved',
            'approved_at'     => $now,
            'tenant_id'       => $tenantId,
            'dispatch_id'     => (int) $dispatch['id'],
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        return (int) $db->insertID();
    }

    /** @return list<mixed> */
    private static function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) { // double-encoded by an older release
                $decoded = json_decode($decoded, true);
            }
            return is_array($decoded) ? array_values($decoded) : [];
        }
        return [];
    }

    private static function providerError(\Throwable $e): ?SocialApiException
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof SocialApiException) {
                return $x;
            }
        }
        return null;
    }

    public static function failureClass(\Throwable $e): string
    {
        $api = $e instanceof SocialApiException ? $e : null;
        if ($e instanceof ProviderNotConfiguredException) {
            return 'not_configured';
        }
        if ($api !== null) {
            return match ($api->kind) {
                SocialApiException::NOT_CONFIGURED, SocialApiException::AUTH => 'not_configured',
                SocialApiException::RATE_LIMIT                              => 'rate_limit',
                SocialApiException::TRANSIENT                               => 'transient',
                SocialApiException::REJECTED, SocialApiException::CONFLICT  => 'rejected',
                SocialApiException::UNRECOGNISED                            => 'unknown',
                default                                                     => 'permanent',
            };
        }
        $code = (int) $e->getCode();
        return match (true) {
            $code === 429                => 'rate_limit',
            $code >= 400 && $code < 500  => 'permanent',
            $code >= 500                 => 'transient',
            default                      => 'unknown',
        };
    }

    /** @return array<string, mixed> provider error code + redacted response for the post row */
    private static function failureColumns(\Throwable $e): array
    {
        $api = self::providerError($e);
        if ($api === null) {
            return ['provider_status' => 'error'];
        }
        return [
            'provider_status'        => 'error',
            'provider_error_code'    => $api->errorCode !== null ? mb_substr($api->errorCode, 0, 120) : $api->kind,
            'provider_response_json' => json_encode(['error_kind' => $api->kind, 'http_status' => $api->httpStatus, 'response' => $api->response, 'targets' => $api->targets], JSON_UNESCAPED_SLASHES),
        ];
    }
}

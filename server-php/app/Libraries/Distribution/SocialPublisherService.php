<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\ProviderNotConfiguredException;
use App\Libraries\Distribution\Providers\SocialPublisherInterface;
use App\Models\Distribution\CampaignDispatchModel;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\CampaignOperationalMetricsModel;

class SocialPublisherService
{
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

        $idempotencyKey = 'social-dispatch:' . $postId . ':' . ($post['uuid'] ?? $postId);

        // Idempotency via delivery attempts: a post that was accepted (or is in
        // flight) is not sent again; a failed attempt may be retried.
        $latest = $this->attemptModel->like('idempotency_key', $idempotencyKey, 'after')->orderBy('id', 'DESC')->first();
        if ($latest !== null) {
            if (in_array($latest['status'] ?? '', ['accepted', 'sent', 'delivered', 'sending'], true)) {
                return ['status' => 'already_dispatched', 'attempt' => $latest];
            }
            $idempotencyKey .= ':retry:' . bin2hex(random_bytes(4));
        }

        $platform = $post['channel'] ?? 'linkedin';

        $message = new ChannelMessage(
            idempotencyKey:   $idempotencyKey,
            recipientAddress: (string) ($post['destination_id'] ?? 'default-page'),
            content:          (string) $post['content'],
            mediaRefs:        is_string($post['media_refs'] ?? null) ? (json_decode($post['media_refs'], true) ?? []) : [],
            metadata:         ['platform' => $platform, 'hashtags' => $post['hashtags'] ?? '[]'],
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
            if (!$receipt->isAccepted()) {
                throw new \RuntimeException('Social provider did not accept the post (status ' . $receipt->status . ').', 502);
            }

            $this->attemptModel->update($attemptId, [
                'status'              => 'accepted',
                'provider_message_id' => $receipt->providerMessageId,
                'remote_url'          => $receipt->remoteUrl,
                'accepted_at'         => date('Y-m-d H:i:s'),
            ]);

            $db->table('reach_social_posts')->where('id', $postId)->update([
                'status'         => 'posted',
                'published_at'   => date('Y-m-d H:i:s'),
                'external_post_id' => $receipt->providerMessageId,
                'remote_post_id' => $receipt->providerMessageId,
                'remote_url'     => $receipt->remoteUrl,
                'provider'       => $this->publisher->providerName(),
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
            $code  = (int) $e->getCode();
            $class = match (true) {
                $e instanceof ProviderNotConfiguredException => 'not_configured',
                $code === 429                                => 'rate_limit',
                $code >= 400 && $code < 500                  => 'permanent',
                $code >= 500                                 => 'transient',
                default                                      => 'unknown',
            };
            $this->attemptModel->update($attemptId, [
                'status'        => 'failed',
                'failure_class' => $class,
                'failure_detail'=> $e->getMessage(),
                'failed_at'     => date('Y-m-d H:i:s'),
            ]);

            // Honest outcome: never `posted`. The error tells the operator why.
            $db->table('reach_social_posts')->where('id', $postId)->update([
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 2000),
                'provider'      => $this->publisher->providerName(),
            ]);

            $this->audit->record(AuditLogger::DISTRIBUTION_SOCIAL_FAILED, [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ], $actorId);

            throw $e;
        }
    }

    /**
     * Publish a campaign's social channel variant (fan-out path). The dispatch
     * row ends `completed` only when the provider accepted the post.
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
        $variant = $db->table('reach_campaign_channel_variants')->where('id', $variantId)->get()->getRowArray();
        if ($variant === null) {
            throw new \RuntimeException('Channel variant not found.', 404);
        }
        $content  = is_array($variant['content_json']) ? $variant['content_json'] : (json_decode((string) ($variant['content_json'] ?? '{}'), true) ?: []);
        $platform = (string) ($content['platform'] ?? 'linkedin');
        $key      = 'd' . $dispatchId . ':social:' . $variantId;
        $now      = date('Y-m-d H:i:s');

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
                content:          (string) ($content['text'] ?? $content['content'] ?? $content['body'] ?? ''),
                mediaRefs:        (array) ($content['media_refs'] ?? []),
                metadata:         ['platform' => $platform],
            ), $platform);
            if (!$receipt->isAccepted()) {
                throw new \RuntimeException('Social provider did not accept the post (status ' . $receipt->status . ').', 502);
            }
            $this->attemptModel->update($attemptId, [
                'status' => 'accepted', 'provider_message_id' => $receipt->providerMessageId,
                'remote_url' => $receipt->remoteUrl, 'accepted_at' => date('Y-m-d H:i:s'),
            ]);
            $status = 'completed';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->attemptModel->update($attemptId, [
                'status' => 'failed',
                'failure_class' => $e instanceof ProviderNotConfiguredException ? 'not_configured' : 'unknown',
                'failure_detail' => $error, 'failed_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $db->table('reach_campaign_dispatches')->where('id', $dispatchId)->update([
            'status'       => $status,
            'sent_count'   => $status === 'completed' ? 1 : 0,
            'failed_count' => $status === 'completed' ? 0 : 1,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'last_error'   => $error,
        ]);
        $this->audit->record($status === 'completed' ? AuditLogger::DISTRIBUTION_SOCIAL_POSTED : AuditLogger::DISTRIBUTION_SOCIAL_FAILED, [
            'dispatch_id' => $dispatchId, 'variant_id' => $variantId, 'platform' => $platform, 'error' => $error,
        ], null);
        (new ChannelDispatchService(null, null, $this->audit))->settleParentCampaign((int) $dispatch['campaign_id']);

        return ['status' => $status, 'dispatch_id' => $dispatchId, 'error' => $error];
    }

    public function getStatus(int $postId): array
    {
        $db   = \Config\Database::connect();
        $post = $db->table('reach_social_posts')->where('id', $postId)->get()->getRowArray();
        if ($post === null) {
            return ['status' => 'not_found'];
        }
        if (!empty($post['external_post_id'])) {
            try {
                $status = $this->publisher->getStatus((string) $post['external_post_id']);
                return ['status' => $status->normalisedStatus, 'provider_status' => $status];
            } catch (\Throwable) {
                // Provider unavailable — return cached status
            }
        }
        return ['status' => $post['status'] ?? 'unknown'];
    }
}

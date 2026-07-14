<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
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

        // Check idempotency via delivery attempts
        $existing = $this->attemptModel->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return ['status' => 'already_dispatched', 'attempt' => $existing];
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
            $this->attemptModel->update($attemptId, [
                'status'        => 'failed',
                'failure_class' => 'transient',
                'failure_detail'=> $e->getMessage(),
                'failed_at'     => date('Y-m-d H:i:s'),
            ]);

            $db->table('reach_social_posts')->where('id', $postId)->update(['status' => 'failed']);

            $this->audit->record(AuditLogger::DISTRIBUTION_SOCIAL_FAILED, [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ], $actorId);

            throw $e;
        }
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

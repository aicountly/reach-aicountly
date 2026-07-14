<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\EmailSenderInterface;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelSuppressionModel;

class EmailSenderService
{
    private EmailSenderInterface $sender;

    public function __construct(
        private readonly CampaignDeliveryAttemptModel $attemptModel,
        private readonly ChannelSuppressionModel      $suppressionModel,
        private readonly SuppressionService           $suppressionService,
        private readonly AuditLogger                  $audit,
    ) {
        $this->sender = ChannelProviderFactory::makeEmailSender();
    }

    public function dispatch(int $campaignId, int $tenantId, ?int $actorId): array
    {
        $db       = \Config\Database::connect();
        $campaign = $db->table('reach_email_campaigns')->where('id', $campaignId)->get()->getRowArray();

        if ($campaign === null || (int) ($campaign['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('Email campaign not found.', 404);
        }

        if (!in_array($campaign['status'] ?? '', ['approved', 'scheduled'], true)) {
            throw new \RuntimeException('Campaign must be approved before dispatch.', 409);
        }

        $idempotencyKey = 'email-dispatch:' . $campaignId . ':' . ($campaign['uuid'] ?? $campaignId);

        // Check idempotency
        $existing = $this->attemptModel->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return ['status' => 'already_dispatched', 'attempt' => $existing];
        }

        $toAddress = 'list@broadcast'; // In production: resolved from audience snapshot

        $message = new ChannelMessage(
            idempotencyKey:   $idempotencyKey,
            recipientAddress: $toAddress,
            content:          (string) ($campaign['body_html'] ?? $campaign['body_plain_text'] ?? ''),
            metadata:         [
                'subject'    => $campaign['subject'] ?? '',
                'from_name'  => $campaign['from_name'] ?? '',
                'from_email' => $campaign['from_email'] ?? '',
            ],
        );

        $attemptId = $this->attemptModel->insert([
            'dispatch_id'    => null,
            'attempt_number' => 1,
            'status'         => 'sending',
            'provider'       => $this->sender->providerName(),
            'idempotency_key'=> $idempotencyKey,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        try {
            $receipt = $this->sender->send($message);

            $this->attemptModel->update($attemptId, [
                'status'              => 'accepted',
                'provider_message_id' => $receipt->providerMessageId,
                'accepted_at'         => date('Y-m-d H:i:s'),
            ]);

            $db->table('reach_email_campaigns')->where('id', $campaignId)->update([
                'status'  => 'sent',
                'sent_at' => date('Y-m-d H:i:s'),
                'stats'   => json_encode(['dispatched_at' => date('c'), 'provider' => $this->sender->providerName()]),
            ]);

            $this->audit->record(AuditLogger::DISTRIBUTION_EMAIL_ACCEPTED, [
                'campaign_id'         => $campaignId,
                'provider_message_id' => $receipt->providerMessageId,
            ], $actorId);

            return ['status' => 'dispatched', 'receipt' => [
                'provider_message_id' => $receipt->providerMessageId,
                'status'              => $receipt->status,
            ]];
        } catch (\Throwable $e) {
            $this->attemptModel->update($attemptId, [
                'status'         => 'failed',
                'failure_class'  => 'transient',
                'failure_detail' => $e->getMessage(),
                'failed_at'      => date('Y-m-d H:i:s'),
            ]);
            $db->table('reach_email_campaigns')->where('id', $campaignId)->update(['status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Handle bounce/complaint/unsubscribe from provider callback.
     * Adds address to suppression list automatically.
     */
    public function handleCallback(int $tenantId, string $eventType, string $address, ?string $providerEventId = null, ?int $actorId = null): void
    {
        $reason = match($eventType) {
            'bounce'      => \App\Enums\SuppressionReason::Bounce,
            'complaint'   => \App\Enums\SuppressionReason::Complaint,
            'unsubscribe' => \App\Enums\SuppressionReason::Unsubscribe,
            default       => \App\Enums\SuppressionReason::Manual,
        };

        $this->suppressionService->suppress(
            $tenantId,
            'email',
            $address,
            $reason,
            'provider_callback:' . $eventType,
            $actorId,
        );

        $eventConst = match($eventType) {
            'bounce'      => AuditLogger::DISTRIBUTION_EMAIL_BOUNCED,
            'complaint'   => AuditLogger::DISTRIBUTION_EMAIL_COMPLAINED,
            'unsubscribe' => AuditLogger::DISTRIBUTION_EMAIL_UNSUBSCRIBED,
            default       => AuditLogger::DISTRIBUTION_EMAIL_BOUNCED,
        };

        $this->audit->record($eventConst, [
            'address_masked' => ChannelSuppressionModel::maskAddress($address),
            'event_type'     => $eventType,
            'provider_event' => $providerEventId,
        ], $actorId);
    }

    public function getStatus(int $campaignId): array
    {
        $db       = \Config\Database::connect();
        $campaign = $db->table('reach_email_campaigns')->where('id', $campaignId)->get()->getRowArray();
        if ($campaign === null) {
            return ['status' => 'not_found'];
        }
        return ['status' => $campaign['status'] ?? 'unknown', 'sent_at' => $campaign['sent_at'] ?? null];
    }
}

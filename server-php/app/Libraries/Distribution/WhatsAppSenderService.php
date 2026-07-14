<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\WhatsAppSenderInterface;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelConsentModel;
use App\Models\Distribution\ChannelSuppressionModel;

class WhatsAppSenderService
{
    private WhatsAppSenderInterface $sender;

    public function __construct(
        private readonly CampaignDeliveryAttemptModel $attemptModel,
        private readonly ChannelConsentModel          $consentModel,
        private readonly SuppressionService           $suppressionService,
        private readonly AuditLogger                  $audit,
    ) {
        $this->sender = ChannelProviderFactory::makeWhatsAppSender();
    }

    /**
     * Validate opt-in before dispatch.
     * Only recipients with 'granted' consent for 'whatsapp' channel can receive messages.
     */
    public function validateOptIn(int $tenantId, string $recipientPhone): bool
    {
        if ($this->suppressionService->isSuppressed($tenantId, 'whatsapp', $recipientPhone)) {
            return false;
        }
        return $this->consentModel->isGranted($tenantId, 'whatsapp', 'contact', $recipientPhone, 'marketing');
    }

    public function dispatch(int $campaignId, int $tenantId, ?int $actorId): array
    {
        $db       = \Config\Database::connect();
        $campaign = $db->table('reach_whatsapp_campaigns')->where('id', $campaignId)->get()->getRowArray();

        if ($campaign === null || (int) ($campaign['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('WhatsApp campaign not found.', 404);
        }

        if (!in_array($campaign['status'] ?? '', ['approved', 'scheduled'], true)) {
            throw new \RuntimeException('Campaign must be approved before dispatch.', 409);
        }

        $idempotencyKey = 'whatsapp-dispatch:' . $campaignId . ':' . ($campaign['uuid'] ?? $campaignId);

        $existing = $this->attemptModel->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return ['status' => 'already_dispatched', 'attempt' => $existing];
        }

        $templateId = $campaign['template_id'] ?? null;
        if (empty($templateId)) {
            throw new \RuntimeException('WhatsApp campaign must have a template_id. Freeform messages are not permitted.', 422);
        }

        $message = new ChannelMessage(
            idempotencyKey:   $idempotencyKey,
            recipientAddress: 'broadcast',
            content:          $campaign['body'] ?? '',
            metadata:         [
                'template_id'    => $templateId,
                'template_params'=> is_string($campaign['template_params'] ?? null)
                    ? (json_decode($campaign['template_params'], true) ?? [])
                    : ($campaign['template_params'] ?? []),
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

            $db->table('reach_whatsapp_campaigns')->where('id', $campaignId)->update([
                'status'  => 'sent',
                'sent_at' => date('Y-m-d H:i:s'),
            ]);

            $this->audit->record(AuditLogger::DISTRIBUTION_WHATSAPP_ACCEPTED, [
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
            $db->table('reach_whatsapp_campaigns')->where('id', $campaignId)->update(['status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Get template catalogue from provider.
     */
    public function listTemplates(): array
    {
        return $this->sender->getTemplates();
    }

    public function getStatus(int $campaignId): array
    {
        $db       = \Config\Database::connect();
        $campaign = $db->table('reach_whatsapp_campaigns')->where('id', $campaignId)->get()->getRowArray();
        if ($campaign === null) {
            return ['status' => 'not_found'];
        }
        return ['status' => $campaign['status'] ?? 'unknown', 'sent_at' => $campaign['sent_at'] ?? null];
    }
}

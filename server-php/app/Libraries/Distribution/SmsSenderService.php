<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\SmsSenderInterface;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelSuppressionModel;

class SmsSenderService
{
    private SmsSenderInterface $sender;

    /** Maximum allowed SMS body length (1 segment = 160 GSM-7 chars). For Unicode, cap at 70 to avoid over-billing. */
    private const MAX_BODY_LENGTH = 160;

    public function __construct(
        private readonly CampaignDeliveryAttemptModel $attemptModel,
        private readonly SuppressionService           $suppressionService,
        private readonly AuditLogger                  $audit,
    ) {
        $this->sender = ChannelProviderFactory::makeSmsSender();
    }

    /**
     * Validate DLT compliance fields (required for India TRAI compliance).
     * DLT entity_id, template_id, and sender_id must be non-empty when the
     * provider requires DLT registration.
     */
    public function validateDlt(array $campaign): void
    {
        $caps = $this->sender->getCapabilities();
        if (!($caps['dlt_required'] ?? false)) {
            return;
        }
        foreach (['dlt_entity_id', 'dlt_template_id', 'dlt_sender_id'] as $field) {
            if (empty($campaign[$field])) {
                throw new \RuntimeException(
                    "DLT compliance: {$field} is required for this SMS provider.",
                    422
                );
            }
        }
    }

    public function dispatch(int $campaignId, int $tenantId, ?int $actorId): array
    {
        $db       = \Config\Database::connect();
        $smsCamp  = $db->table('reach_sms_campaigns')->where('campaign_id', $campaignId)->get()->getRowArray();

        if ($smsCamp === null || (int) ($smsCamp['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('SMS campaign not found.', 404);
        }

        $campaign = $db->table('reach_campaigns')->where('id', $campaignId)->get()->getRowArray();
        if ($campaign === null) {
            throw new \RuntimeException('Parent campaign not found.', 404);
        }

        if (!in_array($campaign['status'] ?? '', ['approved', 'scheduled'], true)) {
            throw new \RuntimeException('Campaign must be approved before dispatch.', 409);
        }

        $this->validateDlt($smsCamp);

        $body = $campaign['description'] ?? '';
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \RuntimeException(
                'SMS body exceeds ' . self::MAX_BODY_LENGTH . ' characters. Shorten the message.',
                422
            );
        }

        $idempotencyKey = 'sms-dispatch:' . $campaignId . ':' . ($campaign['uuid'] ?? $campaignId);

        $existing = $this->attemptModel->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return ['status' => 'already_dispatched', 'attempt' => $existing];
        }

        $message = new ChannelMessage(
            idempotencyKey:   $idempotencyKey,
            recipientAddress: 'broadcast',
            content:          $body,
            metadata:         [
                'dlt_entity_id'  => $smsCamp['dlt_entity_id'] ?? null,
                'dlt_template_id'=> $smsCamp['dlt_template_id'] ?? null,
                'dlt_sender_id'  => $smsCamp['dlt_sender_id'] ?? null,
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

            $db->table('reach_campaigns')->where('id', $campaignId)->update([
                'status'     => 'completed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->audit->record(AuditLogger::DISTRIBUTION_SMS_ACCEPTED, [
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
            $db->table('reach_campaigns')->where('id', $campaignId)->update(['status' => 'failed']);
            throw $e;
        }
    }

    public function getStatus(int $campaignId): array
    {
        $db      = \Config\Database::connect();
        $smsCamp = $db->table('reach_sms_campaigns')->where('campaign_id', $campaignId)->get()->getRowArray();
        if ($smsCamp === null) {
            return ['status' => 'not_found'];
        }
        $campaign = $db->table('reach_campaigns')->where('id', $campaignId)->get()->getRowArray();
        return ['status' => $campaign['status'] ?? 'unknown'];
    }

    public function getCapabilities(): array
    {
        return $this->sender->getCapabilities();
    }
}

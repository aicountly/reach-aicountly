<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\SmsSenderInterface;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelSuppressionModel;

class SmsSenderService
{
    private SmsSenderInterface $sender;
    private ?ChannelDispatchService $dispatcher = null;

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

    /**
     * Queue an SMS campaign send (reach_campaigns.id with its reach_sms_campaigns
     * row). DLT fields and body length are validated up front; recipients need a
     * recorded marketing opt-in. Sent in batches by distribution.dispatch.sms.
     */
    public function dispatch(int $campaignId, int $tenantId, ?int $actorId): array
    {
        return $this->dispatcher()->start(ChannelDispatchService::SOURCE_SMS_CAMPAIGN, $campaignId, $tenantId, $actorId);
    }

    public function setDispatcher(ChannelDispatchService $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    private function dispatcher(): ChannelDispatchService
    {
        return $this->dispatcher ??= new ChannelDispatchService(null, null, $this->audit);
    }

    public function getStatus(int $campaignId): array
    {
        $db      = \Config\Database::connect();
        $smsCamp = $db->table('reach_sms_campaigns')->where('campaign_id', $campaignId)->get()->getRowArray();
        if ($smsCamp === null) {
            return ['status' => 'not_found'];
        }
        $campaign = $db->table('reach_campaigns')->where('id', $campaignId)->get()->getRowArray();
        return [
            'status'     => $campaign['status'] ?? 'unknown',
            'sms_status' => $smsCamp['status'] ?? null,
            'dispatch'   => EmailSenderService::latestDispatch($db, ChannelDispatchService::SOURCE_SMS_CAMPAIGN, $campaignId),
        ];
    }

    public function getCapabilities(): array
    {
        return $this->sender->getCapabilities();
    }
}

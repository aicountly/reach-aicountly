<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\WhatsAppSenderInterface;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelConsentModel;
use App\Models\Distribution\ChannelSuppressionModel;

class WhatsAppSenderService
{
    private WhatsAppSenderInterface $sender;
    private ?ChannelDispatchService $dispatcher = null;

    public function __construct(
        private readonly CampaignDeliveryAttemptModel $attemptModel,
        private readonly ChannelConsentModel          $consentModel,
        private readonly SuppressionService           $suppressionService,
        private readonly AuditLogger                  $audit,
    ) {
        $this->sender = ChannelProviderFactory::makeWhatsAppSender();
    }

    /**
     * Opt-in check for one number: not suppressed and a lead with that number
     * has a current `granted` WhatsApp marketing consent.
     */
    public function validateOptIn(int $tenantId, string $recipientPhone): bool
    {
        return (new AudienceResolverService())->hasOptIn($tenantId, 'whatsapp', $recipientPhone);
    }

    /**
     * Queue a WhatsApp template broadcast. Only opted-in, unsuppressed leads are
     * in the snapshot; sent in batches by distribution.dispatch.whatsapp.
     */
    public function dispatch(int $campaignId, int $tenantId, ?int $actorId): array
    {
        return $this->dispatcher()->start(ChannelDispatchService::SOURCE_WHATSAPP_CAMPAIGN, $campaignId, $tenantId, $actorId);
    }

    public function setDispatcher(ChannelDispatchService $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    private function dispatcher(): ChannelDispatchService
    {
        return $this->dispatcher ??= new ChannelDispatchService(null, null, $this->audit);
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
        return [
            'status'   => $campaign['status'] ?? 'unknown',
            'sent_at'  => $campaign['sent_at'] ?? null,
            'dispatch' => EmailSenderService::latestDispatch($db, ChannelDispatchService::SOURCE_WHATSAPP_CAMPAIGN, $campaignId),
        ];
    }
}

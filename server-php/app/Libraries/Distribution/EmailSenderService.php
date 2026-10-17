<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\EmailSenderInterface;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelSuppressionModel;

class EmailSenderService
{
    private EmailSenderInterface $sender;
    private ?ChannelDispatchService $dispatcher = null;

    public function __construct(
        private readonly CampaignDeliveryAttemptModel $attemptModel,
        private readonly ChannelSuppressionModel      $suppressionModel,
        private readonly SuppressionService           $suppressionService,
        private readonly AuditLogger                  $audit,
    ) {
        $this->sender = ChannelProviderFactory::makeEmailSender();
    }

    /**
     * Queue a campaign send: the audience is resolved from the campaign's
     * audience_filter (consent + suppression applied, deduped, capped) and sent
     * in batches by the distribution.dispatch.email job. The campaign becomes
     * `sent` only once a real provider has accepted at least one message.
     */
    public function dispatch(int $campaignId, int $tenantId, ?int $actorId): array
    {
        return $this->dispatcher()->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId, $tenantId, $actorId);
    }

    /** Send one copy to an explicitly named test address (no campaign status change). */
    public function testSend(int $campaignId, int $tenantId, string $address, ?int $actorId): array
    {
        return $this->dispatcher()->testSend(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId, $tenantId, $address, $actorId);
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
        return [
            'status'   => $campaign['status'] ?? 'unknown',
            'sent_at'  => $campaign['sent_at'] ?? null,
            'dispatch' => self::latestDispatch($db, ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function latestDispatch($db, string $sourceType, int $sourceId): ?array
    {
        try {
            return $db->table('reach_campaign_dispatches')->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->orderBy('id', 'DESC')->get()->getRowArray();
        } catch (\Throwable) {
            return null;
        }
    }
}

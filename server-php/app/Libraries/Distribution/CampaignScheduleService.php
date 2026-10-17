<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\JobService;

class CampaignScheduleService
{
    public function __construct(
        private readonly JobService   $jobs,
        private readonly AuditLogger  $audit,
    ) {}

    /**
     * Schedule a campaign for dispatch at a specific UTC time.
     * Enqueues a CAMPAIGN_SCHEDULE job with the exact send time.
     */
    public function schedule(int $campaignId, string $scheduledAt, int $tenantId, ?int $actorId): array
    {
        $db       = \Config\Database::connect();
        $campaign = $db->table('reach_campaigns')->where('id', $campaignId)->get()->getRowArray();

        if ($campaign === null || (int) ($campaign['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('Campaign not found.', 404);
        }

        if (($campaign['status'] ?? '') !== 'approved') {
            throw new \RuntimeException('Campaign must be approved before scheduling.', 409);
        }

        try {
            $sendAt = new \DateTimeImmutable($scheduledAt, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new \RuntimeException('scheduled_at is not a valid date/time.', 422);
        }
        $sendAt = $sendAt->setTimezone(new \DateTimeZone('UTC'));
        if ($sendAt->getTimestamp() < time() - 60) {
            throw new \RuntimeException('scheduled_at is in the past.', 422);
        }

        $jobId = $this->jobs->enqueueAt(
            DistributionJobTypes::CAMPAIGN_SCHEDULE,
            ['campaign_id' => $campaignId, 'tenant_id' => $tenantId],
            $sendAt->format('Y-m-d H:i:s'),
            ['queue' => 'distribution', 'max_attempts' => 3, 'priority' => 50],
        );

        $db->table('reach_campaigns')->where('id', $campaignId)->update([
            'status'       => 'scheduled',
            'scheduled_at' => $sendAt->format('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_SCHEDULED, [
            'campaign_id' => $campaignId,
            'scheduled_at'=> $scheduledAt,
            'job_id'      => $jobId,
        ], $actorId);

        return ['job_id' => $jobId, 'scheduled_at' => $sendAt->format('c'), 'status' => 'scheduled', 'queue' => ChannelDispatchService::QUEUE];
    }

    /**
     * Cancel a scheduled campaign.
     */
    public function cancel(int $campaignId, int $tenantId, ?int $actorId): void
    {
        $db       = \Config\Database::connect();
        $campaign = $db->table('reach_campaigns')->where('id', $campaignId)->get()->getRowArray();

        if ($campaign === null || (int) ($campaign['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('Campaign not found.', 404);
        }
        if (!in_array($campaign['status'] ?? '', ['scheduled', 'dispatching'], true)) {
            throw new \RuntimeException('Only scheduled or dispatching campaigns can be cancelled.', 409);
        }

        // Unsent batches stop: the dispatch job is a no-op for a cancelled dispatch.
        $db->table('reach_campaign_dispatches')->where('campaign_id', $campaignId)->whereIn('status', ['queued', 'dispatching'])
            ->update(['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s'), 'last_error' => 'Cancelled by operator.']);

        $db->table('reach_campaigns')->where('id', $campaignId)->update([
            'status'     => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_CANCELLED, [
            'campaign_id' => $campaignId,
        ], $actorId);
    }
}

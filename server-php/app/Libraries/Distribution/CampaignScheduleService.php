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

        if (!in_array($campaign['status'] ?? '', ['approved', 'ready_for_review'], true)) {
            throw new \RuntimeException('Campaign must be approved before scheduling.', 409);
        }

        $sendAt = new \DateTimeImmutable($scheduledAt, new \DateTimeZone('UTC'));

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

        return ['job_id' => $jobId, 'scheduled_at' => $scheduledAt, 'status' => 'scheduled'];
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
        if (($campaign['status'] ?? '') !== 'scheduled') {
            throw new \RuntimeException('Only scheduled campaigns can be cancelled.', 409);
        }

        $db->table('reach_campaigns')->where('id', $campaignId)->update([
            'status'     => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_CANCELLED, [
            'campaign_id' => $campaignId,
        ], $actorId);
    }
}

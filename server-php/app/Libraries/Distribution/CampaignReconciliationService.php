<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\JobService;

/**
 * Reconciles campaign dispatch status by querying provider for outstanding messages.
 *
 * Enqueues CAMPAIGN_RECONCILE jobs for dispatches that have been in
 * 'processing' or 'partially_completed' state past their SLA window.
 */
class CampaignReconciliationService
{
    private const SLA_MINUTES = 30;

    public function __construct(
        private readonly JobService  $jobs,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Find stale dispatches and enqueue reconciliation jobs.
     */
    public function reconcileStale(int $tenantId, ?int $actorId): array
    {
        $db       = \Config\Database::connect();
        $slaLimit = date('Y-m-d H:i:s', strtotime('-' . self::SLA_MINUTES . ' minutes'));

        $staleDispatches = $db->table('reach_campaign_dispatches')
            ->whereIn('status', ['processing', 'partially_completed'])
            ->where('updated_at <', $slaLimit)
            ->where('tenant_id', $tenantId)
            ->get()->getResultArray();

        $enqueued = [];
        foreach ($staleDispatches as $dispatch) {
            $jobId = $this->jobs->enqueue(
                DistributionJobTypes::CAMPAIGN_RECONCILE,
                [
                    'dispatch_id' => $dispatch['id'],
                    'campaign_id' => $dispatch['campaign_id'],
                    'channel'     => $dispatch['channel'],
                    'tenant_id'   => $dispatch['tenant_id'],
                ],
                ['queue' => 'distribution', 'max_attempts' => 2, 'priority' => 40],
            );
            $enqueued[] = ['dispatch_id' => $dispatch['id'], 'channel' => $dispatch['channel'], 'job_id' => $jobId];
        }

        if (!empty($enqueued)) {
            $this->audit->record(AuditLogger::DISTRIBUTION_CAMPAIGN_RECONCILED, [
                'count'   => count($enqueued),
                'details' => $enqueued,
            ], $actorId);
        }

        return ['reconciled' => $enqueued, 'total' => count($enqueued)];
    }

    /**
     * Forcibly reconcile a single dispatch.
     */
    public function reconcileDispatch(int $dispatchId, int $tenantId, ?int $actorId): array
    {
        $db       = \Config\Database::connect();
        $dispatch = $db->table('reach_campaign_dispatches')
            ->where('id', $dispatchId)->where('tenant_id', $tenantId)->get()->getRowArray();

        if ($dispatch === null) {
            throw new \RuntimeException('Dispatch not found.', 404);
        }

        $jobId = $this->jobs->enqueue(
            DistributionJobTypes::CAMPAIGN_RECONCILE,
            [
                'dispatch_id' => $dispatch['id'],
                'campaign_id' => $dispatch['campaign_id'],
                'channel'     => $dispatch['channel'],
                'tenant_id'   => $dispatch['tenant_id'],
                'forced'      => true,
            ],
            ['queue' => 'distribution', 'max_attempts' => 1, 'priority' => 80],
        );

        return ['job_id' => $jobId, 'dispatch_id' => $dispatchId];
    }
}

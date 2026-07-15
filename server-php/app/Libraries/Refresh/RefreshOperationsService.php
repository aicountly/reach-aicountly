<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\AuditLogger;
use App\Models\Refresh\ReadinessAuditRunModel;
use App\Models\Refresh\ReadinessFindingModel;
use App\Models\Refresh\RefreshOutcomeWindowModel;
use App\Models\Refresh\RefreshPublicationLinkModel;
use App\Models\Refresh\RefreshRecommendationModel;
use App\Models\Refresh\RefreshWorkflowModel;

/**
 * Aggregates operational health signals across the Phase 9 refresh pipeline
 * for the operations dashboard.
 *
 * All data is read-only. No mutations performed.
 */
class RefreshOperationsService
{
    public function __construct(
        private RefreshRecommendationModel  $recommendationModel,
        private RefreshWorkflowModel        $workflowModel,
        private RefreshPublicationLinkModel $publicationLinkModel,
        private RefreshOutcomeWindowModel   $outcomeWindowModel,
        private ReadinessAuditRunModel      $auditRunModel,
        private ReadinessFindingModel       $findingModel,
        private AuditLogger                 $auditLogger,
    ) {}

    public function getOperationsSummary(int $tenantId): array
    {
        $db = \Config\Database::connect();

        $backlogCount = $this->recommendationModel
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['recommended', 'triaged'])
            ->countAllResults();

        $activeWorkflows = $this->workflowModel
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['outcome_recorded', 'rejected', 'cancelled', 'withdrawn', 'superseded', 'failed'])
            ->countAllResults();

        $failedPublications = $this->publicationLinkModel
            ->where('delivery_status', 'failed')
            ->countAllResults();

        $pendingOutcomes = $this->outcomeWindowModel
            ->where('measurement_status', 'pending')
            ->countAllResults();

        $openBlockers = $this->findingModel->getOpenBlockers();

        return [
            'recommendation_backlog' => $backlogCount,
            'active_workflows'       => $activeWorkflows,
            'failed_publications'    => $failedPublications,
            'pending_outcome_windows'=> $pendingOutcomes,
            'open_critical_findings' => count(array_filter($openBlockers, fn($f) => $f['severity'] === 'critical')),
            'open_high_findings'     => count(array_filter($openBlockers, fn($f) => $f['severity'] === 'high')),
        ];
    }

    public function getJobReliabilityReport(int $tenantId): array
    {
        $db = \Config\Database::connect();

        $jobs = $db->query(
            "SELECT job_type, COUNT(*) AS total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    MAX(started_at) AS last_run
             FROM reach_jobs
             WHERE created_at >= NOW() - INTERVAL '7 days'
             GROUP BY job_type
             ORDER BY total DESC"
        )->getResultArray();

        return [
            'window'   => '7_days',
            'job_types'=> $jobs,
        ];
    }
}

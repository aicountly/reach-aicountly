<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Readiness;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Refresh\RefreshOperationsService;
use App\Models\Refresh\ReadinessAuditRunModel;
use App\Models\Refresh\ReadinessFindingModel;
use App\Models\Refresh\RefreshOutcomeWindowModel;
use App\Models\Refresh\RefreshPublicationLinkModel;
use App\Models\Refresh\RefreshRecommendationModel;
use App\Models\Refresh\RefreshWorkflowModel;
use CodeIgniter\HTTP\ResponseInterface;

class OperationsController extends BaseApiController
{
    public function summary(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $service = new RefreshOperationsService(
                new RefreshRecommendationModel(),
                new RefreshWorkflowModel(),
                new RefreshPublicationLinkModel(),
                new RefreshOutcomeWindowModel(),
                new ReadinessAuditRunModel(),
                new ReadinessFindingModel(),
                new AuditLogger(),
            );

            return $this->ok([
                'summary' => $service->getOperationsSummary($tenantId),
                'jobs'    => $service->getJobReliabilityReport($tenantId),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'OperationsController::summary: ' . $e->getMessage());
            return $this->ok([
                'summary' => [
                    'recommendation_backlog'  => 0,
                    'active_workflows'        => 0,
                    'failed_publications'     => 0,
                    'pending_outcome_windows' => 0,
                    'open_critical_findings'  => 0,
                    'open_high_findings'      => 0,
                ],
                'jobs' => ['window' => '7_days', 'job_types' => []],
            ]);
        }
    }
}

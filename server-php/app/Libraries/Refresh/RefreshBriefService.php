<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\AuditLogger;
use App\Models\Refresh\RefreshBriefModel;
use App\Models\Refresh\RefreshWorkflowModel;
use RuntimeException;

class RefreshBriefService
{
    public function __construct(
        private RefreshBriefModel    $briefModel,
        private RefreshWorkflowModel $workflowModel,
        private AuditLogger          $auditLogger,
    ) {}

    public function createBrief(
        int    $workflowId,
        int    $evidenceSnapshotId,
        string $refreshObjective,
        array  $keyChanges,
        array  $targetSections,
        array  $sourceRequirements,
        int    $createdBy,
    ): array {
        $workflow = $this->workflowModel->find($workflowId);
        if (! $workflow) {
            throw new RuntimeException("Workflow {$workflowId} not found");
        }
        if ($this->briefModel->getForWorkflow($workflowId) !== null) {
            throw new RuntimeException("Brief already exists for workflow {$workflowId}");
        }

        $id = $this->briefModel->insert([
            'workflow_id'          => $workflowId,
            'evidence_snapshot_id' => $evidenceSnapshotId,
            'refresh_objective'    => $refreshObjective,
            'key_changes'          => json_encode($keyChanges),
            'target_sections'      => json_encode($targetSections),
            'source_requirements'  => json_encode($sourceRequirements),
            'created_by'           => $createdBy,
        ]);

        $this->auditLogger->log(
            userId:     $createdBy,
            action:     AuditLogger::REFRESH_BRIEF_CREATED,
            entityType: 'refresh_brief',
            entityId:   $id,
            extra:      ['workflow_id' => $workflowId],
        );

        return $this->briefModel->find($id);
    }

    public function getBriefForWorkflow(int $workflowId): ?array
    {
        return $this->briefModel->getForWorkflow($workflowId);
    }
}

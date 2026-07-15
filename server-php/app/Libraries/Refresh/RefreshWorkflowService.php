<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Enums\RefreshWorkflowStatus;
use App\Libraries\ApprovalPolicy;
use App\Libraries\AuditLogger;
use App\Models\Refresh\RefreshRecommendationModel;
use App\Models\Refresh\RefreshWorkflowModel;
use RuntimeException;

/**
 * Refresh workflow state machine.
 *
 * All state transitions are validated against RefreshWorkflowTransitions.
 * Optimistic concurrency via lock_version prevents lost-update races.
 * Self-approval is prevented via ApprovalPolicy.
 */
class RefreshWorkflowService
{
    public function __construct(
        private RefreshWorkflowModel       $workflowModel,
        private RefreshRecommendationModel $recommendationModel,
        private ApprovalPolicy             $approvalPolicy,
        private AuditLogger                $auditLogger,
    ) {}

    public function create(
        int    $tenantId,
        int    $recommendationId,
        int    $contentIdentityId,
        string $refreshObjective,
        string $riskClassification,
        ?int   $assignedTo,
        ?string $dueDate,
    ): array {
        $id = $this->workflowModel->insert([
            'tenant_id'          => $tenantId,
            'recommendation_id'  => $recommendationId,
            'content_identity_id'=> $contentIdentityId,
            'status'             => RefreshWorkflowStatus::Accepted->value,
            'lock_version'       => 0,
            'refresh_objective'  => $refreshObjective,
            'risk_classification'=> $riskClassification,
            'assigned_to'        => $assignedTo,
            'due_date'           => $dueDate,
        ]);

        $this->recommendationModel->update($recommendationId, [
            'status' => 'accepted',
        ]);

        return $this->workflowModel->find($id);
    }

    public function transition(
        int    $workflowId,
        string $toStatus,
        int    $lockVersion,
        int    $actorId,
        array  $extra = [],
    ): array {
        $workflow = $this->workflowModel->find($workflowId);
        if (! $workflow) {
            throw new RuntimeException("Workflow {$workflowId} not found");
        }
        if ((int) $workflow['lock_version'] !== $lockVersion) {
            throw new RuntimeException("Optimistic lock conflict on workflow {$workflowId}");
        }
        if (! RefreshWorkflowTransitions::isAllowed($workflow['status'], $toStatus)) {
            throw new RuntimeException("Transition {$workflow['status']} → {$toStatus} not permitted");
        }

        $update = [
            'status'       => $toStatus,
            'lock_version' => $lockVersion + 1,
        ];

        // Approval requires self-approval check
        if ($toStatus === RefreshWorkflowStatus::Approved->value) {
            $this->approvalPolicy->assertCanApprove($workflow['assigned_to'], $actorId, 'refresh_workflow');
            $update['approved_by'] = $actorId;
            $update['approved_at'] = date('Y-m-d H:i:s');
        }

        if ($toStatus === RefreshWorkflowStatus::Cancelled->value) {
            $update['cancelled_by']    = $actorId;
            $update['cancelled_at']    = date('Y-m-d H:i:s');
            $update['cancel_reason']   = $extra['reason'] ?? null;
        }

        $this->workflowModel->update($workflowId, $update);

        $this->logTransition($workflow['status'], $toStatus, $workflowId, $actorId, $extra);

        return $this->workflowModel->find($workflowId);
    }

    private function logTransition(string $from, string $to, int $workflowId, int $actorId, array $extra): void
    {
        $action = match ($to) {
            'brief_prepared'   => AuditLogger::REFRESH_BRIEF_CREATED,
            'draft_generating' => AuditLogger::REFRESH_DRAFT_GENERATED,
            'in_review'        => AuditLogger::REFRESH_DRAFT_REVIEWED,
            'approved'         => AuditLogger::REFRESH_DRAFT_APPROVED,
            'rejected'         => AuditLogger::REFRESH_DRAFT_REJECTED,
            'published'        => AuditLogger::REFRESH_PUBLISHED,
            'cancelled'        => AuditLogger::REFRESH_CANCELLED,
            'withdrawn'        => AuditLogger::REFRESH_WITHDRAWN,
            default            => 'refresh.workflow.transition',
        };

        $this->auditLogger->log(
            userId:     $actorId,
            action:     $action,
            entityType: 'refresh_workflow',
            entityId:   $workflowId,
            extra:      array_merge(['from' => $from, 'to' => $to], $extra),
        );
    }
}

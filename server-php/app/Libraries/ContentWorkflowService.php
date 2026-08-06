<?php

namespace App\Libraries;

use App\Models\Content\ContentItemModel;
use App\Models\Content\ContentValidationModel;
use App\Models\ApprovalModel;
use App\Libraries\NotificationService;

/**
 * Enforces the content item state machine and multi-stage approval workflow.
 *
 * All transitions MUST go through this service — direct model updates bypass
 * invariants. The service:
 *  - Validates allowed transitions
 *  - Enforces reason requirements on reject/archive/override
 *  - Fires audit events and in-app notifications
 *  - Creates/updates reach_approvals records for the multi-stage workflow
 *
 * Reaching 'published' is driven by the publishing pipeline
 * (PublicationDeploymentService), never by an editor clicking through the
 * workflow — the public site has to accept the deployment first.
 */
class ContentWorkflowService
{
    /**
     * Valid state machine transitions: [from => [to, ...]]
     *
     * validation_pending is an optional staging state — validations are recorded
     * against an item without moving it, so draft and changes_requested submit
     * straight to review_pending (see submit()).
     *
     * 'published' is reachable from approved/scheduled/ready_for_publication:
     * a manual "Publish now" goes straight from approved to the public site,
     * and the previous map had no edge into 'published' at all, so a manually
     * authored item stayed on 'approved' forever even after the public site
     * had accepted and published it.
     */
    private const TRANSITIONS = [
        'idea'                  => ['brief', 'archived'],
        'brief'                 => ['draft', 'idea', 'archived'],
        'draft'                 => ['validation_pending', 'review_pending', 'brief', 'archived'],
        'validation_pending'    => ['review_pending', 'draft', 'archived'],
        'review_pending'        => ['approved', 'changes_requested', 'rejected', 'archived'],
        'changes_requested'     => ['review_pending', 'draft', 'archived'],
        'approved'              => ['scheduled', 'published', 'archived'],
        'scheduled'             => ['ready_for_publication', 'approved', 'published', 'archived'],
        'ready_for_publication' => ['published', 'scheduled', 'archived'],
        'published'             => ['refresh_due', 'archived'],
        'refresh_due'           => ['draft'],
        'rejected'              => ['draft'],
        'archived'              => [],
    ];

    /** Transitions that require a reason. */
    private const REQUIRE_REASON = ['rejected', 'archived', 'changes_requested'];

    /** Approval stages in order. */
    public const STAGES = [
        'editorial_review',
        'subject_matter_review',
        'compliance_review',
        'final_approval',
    ];

    private ContentItemModel      $items;
    private ApprovalModel         $approvals;
    private NotificationService   $notifications;
    private AuditLogger           $audit;

    public function __construct()
    {
        $this->items         = new ContentItemModel();
        $this->approvals     = new ApprovalModel();
        $this->notifications = new NotificationService();
        $this->audit         = new AuditLogger();
    }

    /**
     * Transition a content item to a new workflow status.
     *
     * @throws \RuntimeException on invalid transition or missing reason
     */
    public function transition(int $contentItemId, string $newStatus, array $actor, string $reason = ''): array
    {
        $item = $this->items->find($contentItemId);
        if (!$item) {
            throw new \RuntimeException("Content item {$contentItemId} not found.");
        }

        $from = $item['workflow_status'];
        if (!$this->canTransition($from, $newStatus)) {
            throw new \RuntimeException("Cannot transition from '{$from}' to '{$newStatus}'.");
        }

        if (in_array($newStatus, self::REQUIRE_REASON, true) && empty($reason)) {
            throw new \RuntimeException("A reason is required to transition to '{$newStatus}'.");
        }

        $updates = [
            'workflow_status'    => $newStatus,
            'updated_by_user_id' => $actor['id'] ?? null,
        ];

        if ($newStatus === 'approved') {
            $updates['approved_at'] = date('Y-m-d H:i:s');
            $updates['approved_by'] = $actor['id'] ?? null;
        }
        if ($newStatus === 'archived') {
            $updates['archived_at'] = date('Y-m-d H:i:s');
        }
        if ($newStatus === 'published') {
            $updates['published_at'] = date('Y-m-d H:i:s');
        }

        $this->items->update($contentItemId, $updates);

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_STATUS_CHANGED, 'content', $contentItemId, null, null, [
            'from_status' => $from,
            'to_status'   => $newStatus,
            'reason'      => $reason ?: null,
        ]);

        $this->dispatchTransitionNotifications($item, $newStatus, $actor);

        return $this->items->find($contentItemId);
    }

    /**
     * Submit a content item for editorial review (transition to review_pending).
     * Initialises approval stages as needed.
     */
    public function submit(int $contentItemId, array $actor, string $reason = ''): array
    {
        $item = $this->items->find($contentItemId);
        if (!$item) {
            throw new \RuntimeException("Content item {$contentItemId} not found.");
        }

        if (!in_array($item['workflow_status'], ['draft', 'validation_pending', 'changes_requested'], true)) {
            throw new \RuntimeException("Item must be in draft, validation_pending, or changes_requested to submit for review.");
        }

        $this->transition($contentItemId, 'review_pending', $actor, $reason);

        $stages = $this->requiredStages($item);
        if (!empty($stages)) {
            $this->initApprovalStages($contentItemId, $stages, $actor);
        }

        return $this->items->find($contentItemId);
    }

    /**
     * Approve the current stage (or final approve if single-stage).
     */
    public function approve(int $contentItemId, string $stage, array $actor, string $comment = ''): array
    {
        $item = $this->items->find($contentItemId);
        if (!$item) {
            throw new \RuntimeException("Content item {$contentItemId} not found.");
        }

        // Ensure stage rows exist (handles items submitted before stage was writable).
        $requiredStages = $this->requiredStages($item);
        $requesterActor = ['id' => $item['created_by_user_id'] ?? $actor['id'] ?? null];
        $this->initApprovalStages($contentItemId, $requiredStages, $requesterActor);

        $approval = $this->getCurrentStageApproval($contentItemId, $stage);
        if (!$approval) {
            throw new \RuntimeException("No pending approval found for stage '{$stage}'.");
        }

        // Route already enforces content.approve; still block same-actor self-approval.
        $actorId     = (int) ($actor['id'] ?? 0);
        $requestedBy = (int) ($approval['requested_by'] ?? 0);
        if ($actorId > 0 && $requestedBy > 0 && $actorId === $requestedBy) {
            throw new \RuntimeException(
                'Self-approval is not allowed. Another user with content.approve must approve this item.'
            );
        }

        // UI approve actions send final_approval; complete any still-pending
        // earlier stages so the item can reach workflow_status=approved.
        $stagesToApprove = ($stage === 'final_approval')
            ? $requiredStages
            : [$stage];

        foreach ($stagesToApprove as $stageName) {
            $row = $this->getCurrentStageApproval($contentItemId, $stageName);
            if (!$row) {
                continue;
            }
            $this->approvals->update($row['id'], [
                'decision'   => 'approved',
                'decided_by' => $actor['id'] ?? null,
                'decided_at' => date('Y-m-d H:i:s'),
                'note'       => $comment !== '' ? $comment : null,
            ]);
        }

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_APPROVED, 'content', $contentItemId, null, null, [
            'stage' => $stage,
        ]);

        if ($this->allStagesApproved($contentItemId, $requiredStages)) {
            $this->items->update($contentItemId, [
                'workflow_status' => 'approved',
                'approval_status' => 'approved',
                'approved_at'     => date('Y-m-d H:i:s'),
                'approved_by'     => $actor['id'] ?? null,
            ]);
        }

        return $this->items->find($contentItemId);
    }

    /**
     * Reject a content item at the current stage.
     */
    public function reject(int $contentItemId, string $stage, array $actor, string $reason): array
    {
        if (empty($reason)) {
            throw new \RuntimeException('Rejection reason is required.');
        }

        $approval = $this->getCurrentStageApproval($contentItemId, $stage);
        if ($approval) {
            $this->approvals->update($approval['id'], [
                'decision'   => 'rejected',
                'decided_by' => $actor['id'] ?? null,
                'decided_at' => date('Y-m-d H:i:s'),
                'note'       => $reason,
            ]);
        }

        $this->items->update($contentItemId, [
            'workflow_status' => 'rejected',
            'approval_status' => 'rejected',
        ]);

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_REJECTED, 'content', $contentItemId, null, null, [
            'stage'  => $stage,
            'reason' => $reason,
        ]);

        return $this->items->find($contentItemId);
    }

    /**
     * Request changes on a content item (returns it to changes_requested).
     */
    public function requestChanges(int $contentItemId, array $actor, string $reason): array
    {
        if (empty($reason)) {
            throw new \RuntimeException('Reason for requesting changes is required.');
        }
        return $this->transition($contentItemId, 'changes_requested', $actor, $reason);
    }

    /**
     * Determine which approval stages are required for this content item.
     * Based on: content_type, risk_level, and market.
     */
    public function requiredStages(array $item): array
    {
        $stages = ['editorial_review'];

        $risk = $item['risk_level'] ?? 'low';

        if (in_array($risk, ['high', 'critical'], true)) {
            $stages[] = 'subject_matter_review';
            $stages[] = 'compliance_review';
        } elseif ($risk === 'medium') {
            $stages[] = 'subject_matter_review';
        }

        $stages[] = 'final_approval';
        return array_unique($stages);
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function validNextStatuses(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    private function initApprovalStages(int $contentItemId, array $stages, array $actor): void
    {
        foreach ($stages as $stage) {
            $existing = $this->approvals
                ->where('subject_type', 'content_item')
                ->where('subject_id', $contentItemId)
                ->where('stage', $stage)
                ->first();
            if ($existing) {
                continue;
            }
            $this->approvals->insert([
                'subject_type' => 'content_item',
                'subject_id'   => $contentItemId,
                'decision'     => 'pending',
                'stage'        => $stage,
                'requested_by' => $actor['id'] ?? null,
                'summary'      => "Content item stage: {$stage}",
            ]);
        }
    }

    private function getCurrentStageApproval(int $contentItemId, string $stage): ?array
    {
        return $this->approvals
            ->where('subject_type', 'content_item')
            ->where('subject_id', $contentItemId)
            ->where('stage', $stage)
            ->where('decision', 'pending')
            ->first();
    }

    private function allStagesApproved(int $contentItemId, array $stages): bool
    {
        foreach ($stages as $stage) {
            $approved = $this->approvals
                ->where('subject_type', 'content_item')
                ->where('subject_id', $contentItemId)
                ->where('stage', $stage)
                ->where('decision', 'approved')
                ->countAllResults();
            if (!$approved) {
                return false;
            }
        }
        return true;
    }

    private function dispatchTransitionNotifications(array $item, string $newStatus, array $actor): void
    {
        $actorId = $actor['id'] ?? null;

        $typeMap = [
            'review_pending'    => NotificationService::TYPE_REVIEW_REQUESTED,
            'approved'          => NotificationService::TYPE_CONTENT_APPROVED,
            'rejected'          => NotificationService::TYPE_CONTENT_REJECTED,
            'changes_requested' => NotificationService::TYPE_CHANGES_REQUESTED,
        ];

        $notifType = $typeMap[$newStatus] ?? null;
        if (!$notifType || !$item['created_by_user_id']) {
            return;
        }

        $recipients = [$item['created_by_user_id']];
        if ($actorId && !in_array($actorId, $recipients, true)) {
            $recipients[] = $actorId;
        }

        $this->notifications->dispatchToMany(
            $recipients,
            $notifType,
            "Content '{$item['title']}' status changed to {$newStatus}.",
            [
                'entity_type' => 'content_item',
                'entity_id'   => $item['id'],
                'action_url'  => '/content/' . $item['id'],
                'data'        => [
                    'content_title' => (string) ($item['title'] ?? ''),
                    'status'        => $newStatus,
                ],
            ],
            $actorId
        );
    }
}

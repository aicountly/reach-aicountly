<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Enums\VideoProjectStatus;
use App\Enums\VideoScriptWorkflowStatus;
use App\Libraries\AuditLogger;

/**
 * Phase 6 — Video script editorial workflow service.
 *
 * Enforces the governed editorial workflow for script review and approval,
 * including self-approval prevention (reusing Phase 2/5 ApprovalPolicy pattern).
 *
 * Workflow states:
 *   draft → in_review (submit)
 *   in_review → approved (approve)
 *   in_review → rejected (reject)
 *   in_review → changes_requested (request_changes)
 *   changes_requested → draft (new revision)
 *   approved → render_queued (handled by VideoRenderJobService)
 *
 * Self-approval prevention:
 *   The actor who submitted a version (submitted_by) cannot also approve it.
 */
class VideoWorkflowService
{
    public function __construct(
        private readonly VideoProjectRepository     $projectRepo,
        private readonly VideoScriptRepository      $scriptRepo,
        private readonly VideoScriptVersionService  $versionService = new VideoScriptVersionService(
            new VideoScriptRepository(
                new \App\Models\Video\VideoScriptModel(),
                new \App\Models\Video\VideoScriptVersionModel(),
                new \App\Models\Video\VideoSegmentModel(),
                new \App\Models\Video\VideoCaptionTrackModel(),
                new \App\Models\Video\VideoChapterMarkerModel(),
            )
        ),
    ) {}

    // -------------------------------------------------------------------------
    // Submit for review
    // -------------------------------------------------------------------------

    /**
     * Submit the current script version for editorial review.
     *
     * Transitions script workflow: draft → in_review.
     * Transitions project:         script_draft → script_in_review.
     * Records submitted_by actor on the current version.
     */
    public function submit(int $scriptId, int $submitterId): array
    {
        $script = $this->scriptRepo->findScriptById($scriptId);
        if ($script === null) {
            throw new \RuntimeException("Script #{$scriptId} not found");
        }

        $current = VideoScriptWorkflowStatus::from($script['workflow_status']);
        $validator = new VideoLifecycleValidator();
        $validator->assertScriptTransition($current->value, VideoScriptWorkflowStatus::InReview->value);

        $this->scriptRepo->updateScript($scriptId, ['workflow_status' => VideoScriptWorkflowStatus::InReview->value]);

        $currentVersion = $this->scriptRepo->getCurrentVersion($scriptId);
        if ($currentVersion !== null) {
            $this->scriptRepo->stampVersionSubmitter((int) $currentVersion['id'], $submitterId);
        }

        $project = $this->projectRepo->findById((int) $script['project_id']);
        if ($project !== null) {
            $projectStatus = VideoProjectStatus::from($project['status']);
            if (in_array($projectStatus, [VideoProjectStatus::ScriptDraft], true)) {
                $this->projectRepo->transitionStatusEnum(
                    (int) $project['id'],
                    $projectStatus,
                    VideoProjectStatus::ScriptInReview
                );
            }
        }

        AuditLogger::record(AuditLogger::VIDEO_SCRIPT_GENERATED, [
            'event'     => 'submit',
            'script_id' => $scriptId,
            'actor_id'  => $submitterId,
        ], $submitterId);

        return $this->scriptRepo->findScriptById($scriptId);
    }

    // -------------------------------------------------------------------------
    // Approve
    // -------------------------------------------------------------------------

    /**
     * Approve the current script version.
     *
     * Self-approval prevention: the approver cannot be the same actor who
     * submitted the current version (mirrors Phase 2/5 ApprovalPolicy).
     *
     * Transitions script:   in_review → approved.
     * Transitions project:  script_in_review → script_approved.
     * Stamps approved_by + approved_at on the version.
     */
    public function approve(int $scriptId, int $approverId): array
    {
        $script = $this->scriptRepo->findScriptById($scriptId);
        if ($script === null) {
            throw new \RuntimeException("Script #{$scriptId} not found");
        }

        $current = VideoScriptWorkflowStatus::from($script['workflow_status']);
        $validator = new VideoLifecycleValidator();
        $validator->assertScriptTransition($current->value, VideoScriptWorkflowStatus::Approved->value);

        $currentVersion = $this->scriptRepo->getCurrentVersion($scriptId);
        if ($currentVersion !== null) {
            $submittedBy = (int) ($currentVersion['submitted_by'] ?? 0);
            if ($submittedBy !== 0 && $submittedBy === $approverId) {
                throw new \LogicException(
                    'Self-approval is not permitted. The approver cannot be the same actor who submitted the script.'
                );
            }
            $this->versionService->stampApproval((int) $currentVersion['id'], $approverId);
        }

        $this->scriptRepo->updateScript($scriptId, ['workflow_status' => VideoScriptWorkflowStatus::Approved->value]);

        $project = $this->projectRepo->findById((int) $script['project_id']);
        if ($project !== null) {
            $projectStatus = VideoProjectStatus::from($project['status']);
            if ($projectStatus === VideoProjectStatus::ScriptInReview) {
                $this->projectRepo->transitionStatusEnum(
                    (int) $project['id'],
                    $projectStatus,
                    VideoProjectStatus::ScriptApproved
                );
            }
        }

        AuditLogger::record(AuditLogger::VIDEO_SCRIPT_GENERATED, [
            'event'     => 'approve',
            'script_id' => $scriptId,
            'actor_id'  => $approverId,
        ], $approverId);

        return $this->scriptRepo->findScriptById($scriptId);
    }

    // -------------------------------------------------------------------------
    // Reject
    // -------------------------------------------------------------------------

    /**
     * Reject the current script version.
     *
     * Transitions script:   in_review → rejected.
     * Transitions project:  script_in_review → script_draft (back to rework).
     */
    public function reject(int $scriptId, int $reviewerId, string $reason = ''): array
    {
        $script = $this->scriptRepo->findScriptById($scriptId);
        if ($script === null) {
            throw new \RuntimeException("Script #{$scriptId} not found");
        }

        $current = VideoScriptWorkflowStatus::from($script['workflow_status']);
        $validator = new VideoLifecycleValidator();
        $validator->assertScriptTransition($current->value, VideoScriptWorkflowStatus::Rejected->value);

        $this->scriptRepo->updateScript($scriptId, [
            'workflow_status' => VideoScriptWorkflowStatus::Rejected->value,
        ]);

        AuditLogger::record(AuditLogger::VIDEO_SCRIPT_GENERATED, [
            'event'     => 'reject',
            'script_id' => $scriptId,
            'reason'    => $reason,
            'actor_id'  => $reviewerId,
        ], $reviewerId);

        return $this->scriptRepo->findScriptById($scriptId);
    }

    // -------------------------------------------------------------------------
    // Request changes
    // -------------------------------------------------------------------------

    /**
     * Request changes on the current script version.
     *
     * Transitions script:   in_review → changes_requested.
     * Transitions project:  script_in_review → changes_requested.
     */
    public function requestChanges(int $scriptId, int $reviewerId, string $notes = ''): array
    {
        $script = $this->scriptRepo->findScriptById($scriptId);
        if ($script === null) {
            throw new \RuntimeException("Script #{$scriptId} not found");
        }

        $current = VideoScriptWorkflowStatus::from($script['workflow_status']);
        $validator = new VideoLifecycleValidator();
        $validator->assertScriptTransition($current->value, VideoScriptWorkflowStatus::ChangesRequested->value);

        $this->scriptRepo->updateScript($scriptId, [
            'workflow_status' => VideoScriptWorkflowStatus::ChangesRequested->value,
        ]);

        $project = $this->projectRepo->findById((int) $script['project_id']);
        if ($project !== null) {
            $projectStatus = VideoProjectStatus::from($project['status']);
            if ($projectStatus === VideoProjectStatus::ScriptInReview) {
                $this->projectRepo->transitionStatusEnum(
                    (int) $project['id'],
                    $projectStatus,
                    VideoProjectStatus::ChangesRequested
                );
            }
        }

        AuditLogger::record(AuditLogger::VIDEO_SCRIPT_GENERATED, [
            'event'     => 'request_changes',
            'script_id' => $scriptId,
            'notes'     => $notes,
            'actor_id'  => $reviewerId,
        ], $reviewerId);

        return $this->scriptRepo->findScriptById($scriptId);
    }
}

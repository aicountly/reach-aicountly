<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\Ai\Generation\AiGenerationRequestService;
use App\Libraries\AuditLogger;
use App\Models\Refresh\RefreshBriefModel;
use App\Models\Refresh\RefreshContentVersionLinkModel;
use App\Models\Refresh\RefreshWorkflowModel;
use RuntimeException;

/**
 * Generates a refresh draft by submitting an AI generation request via Phase 3
 * AiGenerationOrchestrator. The brief provides the structured generation input.
 *
 * AI governance:
 * - AI may only generate a draft based on the approved brief and evidence
 * - All generation requests are tracked via reach_ai_generation_requests (Phase 3)
 * - Generated drafts are immutable artifacts once stored
 * - AI cannot approve its own output
 */
class RefreshGenerationService
{
    public function __construct(
        private RefreshWorkflowModel         $workflowModel,
        private RefreshBriefModel            $briefModel,
        private RefreshContentVersionLinkModel $versionLinkModel,
        private AiGenerationRequestService   $generationService,
        private AuditLogger                  $auditLogger,
    ) {}

    public function requestDraft(int $workflowId, int $actorId): array
    {
        $workflow = $this->workflowModel->find($workflowId);
        if (! $workflow) {
            throw new RuntimeException("Workflow {$workflowId} not found");
        }

        $brief = $this->briefModel->getForWorkflow($workflowId);
        if (! $brief) {
            throw new RuntimeException("No brief found for workflow {$workflowId} — create brief first");
        }

        $actor = ['id' => $actorId, 'type' => 'human', 'service' => 'reach:refresh'];

        $generationRequest = $this->generationService->create([
            'content_item_id'  => $workflow['content_identity_id'],
            'schema_type'      => 'refresh_draft',
            'source_context'   => [
                'workflow_id'       => $workflowId,
                'brief_id'          => $brief['id'],
                'refresh_objective' => $brief['refresh_objective'],
                'key_changes'       => json_decode($brief['key_changes'] ?? '[]', true),
                'target_sections'   => json_decode($brief['target_sections'] ?? '[]', true),
            ],
            'generation_params' => [
                'mode'               => 'refresh',
                'require_disclosure' => true,
                'require_sources'    => true,
            ],
        ], $actor);

        $linkId = $this->versionLinkModel->insert([
            'workflow_id'          => $workflowId,
            'generation_artifact_id' => null,
            'version_status'       => 'draft',
        ]);

        $this->auditLogger->log(
            userId:     $actorId,
            action:     AuditLogger::REFRESH_DRAFT_GENERATED,
            entityType: 'refresh_workflow',
            entityId:   $workflowId,
            extra:      [
                'generation_request_id' => $generationRequest['id'],
                'brief_id'              => $brief['id'],
            ],
        );

        return [
            'workflow_id'            => $workflowId,
            'generation_request_id'  => $generationRequest['id'],
            'generation_request_uuid'=> $generationRequest['uuid'],
            'version_link_id'        => $linkId,
        ];
    }

    public function linkArtifact(int $workflowId, int $artifactId, int $actorId): void
    {
        $link = $this->versionLinkModel->where('workflow_id', $workflowId)
                                        ->where('version_status', 'draft')
                                        ->first();
        if (! $link) {
            throw new RuntimeException("No draft version link found for workflow {$workflowId}");
        }

        $this->versionLinkModel->update($link['id'], [
            'generation_artifact_id' => $artifactId,
            'version_status'         => 'draft',
        ]);
    }
}

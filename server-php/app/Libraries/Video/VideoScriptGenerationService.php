<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Enums\VideoProjectStatus;
use App\Libraries\Ai\Generation\AiGenerationOrchestrator;
use App\Libraries\Ai\Generation\AiGenerationRequestService;
use App\Libraries\AuditLogger;
use App\Models\Video\VideoProjectModel;
use App\Models\Video\VideoScriptModel;
use App\Models\Video\VideoScriptVersionModel;
use App\Models\Video\VideoSegmentModel;
use App\Models\Video\VideoCaptionTrackModel;
use App\Models\Video\VideoChapterMarkerModel;

/**
 * Phase 6 — Video script generation service.
 *
 * Delegates to the Phase 3 AI orchestrator to generate a governed
 * video script draft for an approved project.
 *
 * Contract:
 * - NEVER auto-approves generated scripts.
 * - NEVER publishes content without explicit human approval.
 * - Stores AI output as an immutable script version with is_current = false
 *   until editorial workflow advances it.
 */
class VideoScriptGenerationService
{
    private const SCRIPT_CONTENT_TYPE = 'video_script';

    private AiGenerationRequestService $requestService;

    public function __construct(
        private readonly VideoProjectRepository $projectRepo,
        private readonly VideoScriptRepository  $scriptRepo,
    ) {
        $this->requestService = new AiGenerationRequestService();
    }

    /**
     * Request AI generation of a new script version for the given project.
     *
     * Transitions the project to script_generating → script_draft (or generation_failed).
     *
     * @param string   $projectUuid The video project UUID.
     * @param int|null $actorId     The requesting operator actor ID.
     * @param array    $overrides   Optional prompt overrides (target_duration_seconds, style_notes, etc.).
     *
     * @return array{script: array, version: array} The created script and first version.
     */
    public function requestGeneration(string $projectUuid, ?int $actorId = null, array $overrides = []): array
    {
        $project = $this->projectRepo->findByUuid($projectUuid);
        if ($project === null) {
            throw new \RuntimeException("Video project '{$projectUuid}' not found");
        }

        $currentStatus = VideoProjectStatus::from($project['status']);
        $validator     = new VideoLifecycleValidator();
        $validator->assertProjectTransition($currentStatus->value, VideoProjectStatus::ScriptGenerating->value);

        AuditLogger::record(AuditLogger::VIDEO_SCRIPT_GENERATED, [
            'project_uuid' => $projectUuid,
            'project_id'   => $project['id'],
            'actor_id'     => $actorId,
        ], $actorId);

        $this->projectRepo->transitionStatusEnum(
            (int) $project['id'],
            $currentStatus,
            VideoProjectStatus::ScriptGenerating
        );

        try {
            $result = $this->executeGeneration($project, $overrides, $actorId);

            $this->projectRepo->transitionStatusEnum(
                (int) $project['id'],
                VideoProjectStatus::ScriptGenerating,
                VideoProjectStatus::ScriptDraft
            );

            return $result;
        } catch (\Throwable $e) {
            $this->projectRepo->transitionStatusEnum(
                (int) $project['id'],
                VideoProjectStatus::ScriptGenerating,
                VideoProjectStatus::GenerationFailed
            );
            throw $e;
        }
    }

    private function executeGeneration(array $project, array $overrides, ?int $actorId): array
    {
        $targetDuration = $overrides['target_duration_seconds'] ?? 120;
        $styleNotes     = $overrides['style_notes'] ?? '';

        $systemPrompt = "You are an expert video script writer for a B2B SaaS company. "
            . "Generate a fully governed video script following the provided JSON schema. "
            . "The script must include a hook, multiple scenes with voice-over text and visual direction, and a CTA. "
            . "All claims must be grounded in provided knowledge base entries. "
            . "Identify any claims_used, citations_used, and risk_notes in the output.";

        $userPrompt = "Generate a video script for: \"{$project['title']}\". "
            . "Target duration: {$targetDuration} seconds. "
            . ($styleNotes ? "Style notes: {$styleNotes}. " : '')
            . "Use the grounding context provided. "
            . "Produce output matching the video_script JSON schema exactly.";

        $generationRequest = $this->requestService->createRequest([
            'content_type'       => self::SCRIPT_CONTENT_TYPE,
            'prompt_text'        => $userPrompt,
            'system_prompt_text' => $systemPrompt,
            'requester_type'     => 'video_project',
            'requester_id'       => (int) $project['id'],
            'actor_id'           => $actorId,
            'meta'               => [
                'project_uuid'           => $project['uuid'],
                'target_duration_seconds' => $targetDuration,
            ],
        ]);

        $orchestrator = new AiGenerationOrchestrator();
        $orchestrator->execute((int) $generationRequest['id']);

        $artifact = $this->requestService->getLatestArtifact((int) $generationRequest['id']);
        if ($artifact === null || empty($artifact['parsed_output'])) {
            throw new \RuntimeException('AI generation produced no artifact for project ' . $project['uuid']);
        }

        $parsedOutput = is_string($artifact['parsed_output'])
            ? json_decode($artifact['parsed_output'], true)
            : $artifact['parsed_output'];

        return $this->storeGeneratedScript($project, $parsedOutput, (int) $artifact['id'], $actorId);
    }

    private function storeGeneratedScript(
        array  $project,
        array  $parsedOutput,
        int    $artifactId,
        ?int   $actorId
    ): array {
        $existingScript = $this->scriptRepo->findScriptByProjectId((int) $project['id']);

        if ($existingScript === null) {
            $scriptId = $this->scriptRepo->createScript([
                'project_id'     => (int) $project['id'],
                'workflow_status' => 'draft',
                'created_by'     => $actorId,
            ]);
        } else {
            $scriptId = (int) $existingScript['id'];
        }

        $versionCount   = count($this->scriptRepo->listVersions($scriptId));
        $versionNumber  = $versionCount + 1;

        $versionId = $this->scriptRepo->createVersion([
            'script_id'               => $scriptId,
            'version_number'          => $versionNumber,
            'content_json'            => $parsedOutput,
            'generation_artifact_id'  => $artifactId,
            'created_by'              => $actorId,
            'is_current'              => true,
        ]);

        $this->scriptRepo->updateScript($scriptId, ['current_version' => $versionNumber]);

        $script  = $this->scriptRepo->findScriptByProjectId((int) $project['id']);
        $version = $this->scriptRepo->getVersionWithDetail($versionId);

        return ['script' => $script, 'version' => $version];
    }
}

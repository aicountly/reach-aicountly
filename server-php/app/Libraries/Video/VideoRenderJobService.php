<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Enums\VideoProjectStatus;
use App\Enums\VideoRenderJobStatus;
use App\Libraries\AuditLogger;
use App\Libraries\Video\Providers\VideoProviderFactory;
use App\Libraries\Video\Providers\RenderProviderInterface;
use App\Models\Video\VideoRenderJobModel;
use App\Models\Video\VideoRenderAttemptModel;

/**
 * Phase 6 — Video render job lifecycle service.
 *
 * Manages the full render job lifecycle:
 *   queue → reserve → rendering → rendered | failed → retry | dead_letter | cancelled
 *
 * Idempotency:
 *   Creating a job for an already-queued idempotency key returns the
 *   existing job (no duplicate job created).
 *
 * Retry logic:
 *   Jobs are retried up to max_attempts. When max_attempts is exhausted
 *   the job transitions to dead_letter.
 */
class VideoRenderJobService
{
    private const DEFAULT_MAX_ATTEMPTS = 3;

    private RenderProviderInterface $provider;

    public function __construct(
        private readonly VideoRenderJobRepository  $jobRepo,
        private readonly VideoProjectRepository    $projectRepo,
    ) {
        $this->provider = VideoProviderFactory::renderProvider();
    }

    // -------------------------------------------------------------------------
    // Queue
    // -------------------------------------------------------------------------

    /**
     * Queue a render job for an approved script version.
     *
     * Returns an existing job if the idempotency key already exists.
     */
    public function queue(
        array  $project,
        int    $scriptVersionId,
        ?int   $renderProfileId,
        ?int   $actorId,
        string $idempotencyKey = '',
    ): array {
        if ($idempotencyKey === '') {
            $idempotencyKey = 'render:' . $project['uuid'] . ':v' . $scriptVersionId;
        }

        $existing = $this->jobRepo->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $providerName = $this->provider->providerName();

        $newJob = $this->jobRepo->create([
            'project_id'        => (int) $project['id'],
            'script_version_id' => $scriptVersionId,
            'render_profile_id' => $renderProfileId,
            'provider'          => $providerName,
            'idempotency_key'   => $idempotencyKey,
            'status'            => VideoRenderJobStatus::Queued->value,
            'max_attempts'      => self::DEFAULT_MAX_ATTEMPTS,
            'created_by'        => $actorId,
        ]);

        $projectStatus = VideoProjectStatus::from($project['status']);
        if (in_array($projectStatus, [VideoProjectStatus::ScriptApproved], true)) {
            $this->projectRepo->transitionStatusEnum(
                (int) $project['id'],
                $projectStatus,
                VideoProjectStatus::RenderQueued,
            );
        }

        AuditLogger::record(AuditLogger::VIDEO_RENDER_JOB_QUEUED, [
            'job_id'            => $newJob['id'],
            'project_uuid'      => $project['uuid'],
            'script_version_id' => $scriptVersionId,
        ], $actorId);

        return $newJob;
    }

    // -------------------------------------------------------------------------
    // Reserve (worker picks up job)
    // -------------------------------------------------------------------------

    public function reserveNext(): ?array
    {
        return $this->jobRepo->reserveNext();
    }

    // -------------------------------------------------------------------------
    // Dispatch to provider
    // -------------------------------------------------------------------------

    public function dispatch(array $job): array
    {
        $receipt = $this->provider->queue(
            (string) ($job['uuid'] ?? $job['idempotency_key']),
            [
                'project_id'        => $job['project_id'],
                'script_version_id' => $job['script_version_id'],
                'render_profile_id' => $job['render_profile_id'],
            ],
            []
        );

        $this->jobRepo->update((int) $job['id'], [
            'provider_job_id' => $receipt->providerJobId,
            'status'          => VideoRenderJobStatus::Rendering->value,
            'attempt_count'   => ((int) $job['attempt_count']) + 1,
        ]);

        $this->jobRepo->recordAttempt([
            'job_id'          => (int) $job['id'],
            'attempt_number'  => ((int) $job['attempt_count']) + 1,
            'provider'        => $this->provider->providerName(),
            'provider_job_id' => $receipt->providerJobId,
            'status'          => 'dispatched',
            'raw_receipt'     => json_encode($receipt),
        ]);

        return $this->jobRepo->findById((int) $job['id']);
    }

    // -------------------------------------------------------------------------
    // Mark rendered
    // -------------------------------------------------------------------------

    public function markRendered(array $job, ?int $outputAssetId): array
    {
        $this->jobRepo->update((int) $job['id'], [
            'status'          => VideoRenderJobStatus::Rendered->value,
            'output_asset_id' => $outputAssetId,
            'completed_at'    => date('Y-m-d H:i:s'),
        ]);

        $project = $this->projectRepo->findById((int) $job['project_id']);
        if ($project !== null) {
            $ps = VideoProjectStatus::from($project['status']);
            if ($ps === VideoProjectStatus::Rendering) {
                $this->projectRepo->transitionStatusEnum($project['id'], $ps, VideoProjectStatus::Rendered);
            }
        }

        AuditLogger::record(AuditLogger::VIDEO_RENDER_JOB_QUEUED, [
            'event'    => 'rendered',
            'job_id'   => $job['id'],
        ], null);

        return $this->jobRepo->findById((int) $job['id']);
    }

    // -------------------------------------------------------------------------
    // Fail / retry / dead-letter
    // -------------------------------------------------------------------------

    public function markFailed(array $job, string $failureClass = '', string $message = ''): array
    {
        $attempts    = ((int) $job['attempt_count']);
        $maxAttempts = ((int) $job['max_attempts']) ?: self::DEFAULT_MAX_ATTEMPTS;

        $newStatus = $attempts >= $maxAttempts
            ? VideoRenderJobStatus::DeadLetter
            : VideoRenderJobStatus::Failed;

        $this->jobRepo->update((int) $job['id'], [
            'status'        => $newStatus->value,
            'failure_class' => $failureClass,
        ]);

        $this->jobRepo->recordAttempt([
            'job_id'         => (int) $job['id'],
            'attempt_number' => $attempts,
            'provider'       => $this->provider->providerName(),
            'status'         => 'failed',
            'failure_reason' => $message,
        ]);

        return $this->jobRepo->findById((int) $job['id']);
    }

    // -------------------------------------------------------------------------
    // Retry
    // -------------------------------------------------------------------------

    public function retry(array $job, ?int $actorId = null): array
    {
        $currentStatus = VideoRenderJobStatus::from($job['status']);
        $validator     = new VideoLifecycleValidator();
        $validator->assertRenderJobTransition($currentStatus->value, VideoRenderJobStatus::Queued->value);

        $this->jobRepo->update((int) $job['id'], [
            'status'          => VideoRenderJobStatus::Queued->value,
            'reserved_at'     => null,
            'provider_job_id' => null,
        ]);

        AuditLogger::record(AuditLogger::VIDEO_RENDER_JOB_QUEUED, [
            'event'  => 'retried',
            'job_id' => $job['id'],
        ], $actorId);

        return $this->jobRepo->findById((int) $job['id']);
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    public function cancel(array $job, ?int $actorId = null): array
    {
        $currentStatus = VideoRenderJobStatus::from($job['status']);
        $validator     = new VideoLifecycleValidator();
        $validator->assertRenderJobTransition($currentStatus->value, VideoRenderJobStatus::Cancelled->value);

        $this->provider->cancel((string) $job['provider_job_id']);

        $this->jobRepo->update((int) $job['id'], ['status' => VideoRenderJobStatus::Cancelled->value]);

        AuditLogger::record(AuditLogger::VIDEO_RENDER_JOB_QUEUED, [
            'event'  => 'cancelled',
            'job_id' => $job['id'],
        ], $actorId);

        return $this->jobRepo->findById((int) $job['id']);
    }
}

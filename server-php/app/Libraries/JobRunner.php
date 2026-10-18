<?php

declare(strict_types=1);

namespace App\Libraries;

use Throwable;

/**
 * Runs one reserved reach_jobs row and records the outcome — the part of
 * `reach:work` that decides completed / skipped / retry / dead letter.
 *
 * Outcomes:
 *   completed  — the handler returned normally.
 *   skipped    — the handler threw JobSkippedException, or returned a result
 *                whose top-level `status` says it did not run by design
 *                (skipped / not_configured / disabled / not_migrated). Not a
 *                failure: no retry, not counted on the dashboard's failure tile.
 *   dead_letter (immediately) — no handler is registered for the job type, or
 *                the handler threw NonRetryableJobException /
 *                InvalidArgumentException (bad payload). Retrying cannot help.
 *   retry / dead_letter — any other exception: exponential backoff until
 *                max_attempts, then dead letter (JobService::markFailed).
 */
final class JobRunner
{
    /** Result statuses that mean "did not run by design", not "ran and failed". */
    public const SKIP_STATUSES = ['skipped', 'not_configured', 'disabled', 'not_migrated'];

    public function __construct(
        private readonly JobService $jobs,
        private readonly JobHandlerRegistry $registry,
    ) {
    }

    /**
     * @param array<string, mixed> $row the reserved job row
     * @return array{outcome: string, error?: string, reason?: string}
     */
    public function run(array $row, string $workerId): array
    {
        $jobId = (int) $row['id'];
        $type  = (string) $row['job_type'];

        if (! $this->registry->has($type)) {
            $msg = "No handler registered for job_type '{$type}' — nothing can ever run this job.";
            $this->jobs->markFailed($jobId, $msg, false);

            return ['outcome' => 'dead_letter', 'error' => $msg];
        }

        try {
            $result = $this->registry->execute($row, $workerId);
        } catch (JobSkippedException $e) {
            $this->jobs->markSkipped($jobId, $e->getMessage());

            return ['outcome' => 'skipped', 'reason' => $e->getMessage()];
        } catch (NonRetryableJobException|\InvalidArgumentException $e) {
            $this->jobs->markFailed($jobId, $e->getMessage(), false);

            return ['outcome' => 'dead_letter', 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());

            return ['outcome' => 'failed', 'error' => $e->getMessage()];
        }

        $skip = self::skipReason($result);
        if ($skip !== null) {
            $this->jobs->markSkipped($jobId, $skip, $result);

            return ['outcome' => 'skipped', 'reason' => $skip];
        }

        $this->jobs->markCompleted($jobId, $result);

        return ['outcome' => 'completed'];
    }

    /**
     * The skip reason when a handler's result says it did not run by design.
     *
     * @param array<string, mixed> $result
     */
    public static function skipReason(array $result): ?string
    {
        $status = $result['status'] ?? null;
        if (! is_string($status) || ! in_array($status, self::SKIP_STATUSES, true)) {
            return null;
        }
        foreach (['reason', 'detail', 'note'] as $key) {
            if (is_string($result[$key] ?? null) && trim($result[$key]) !== '') {
                return trim($result[$key]);
            }
        }

        return $status;
    }
}

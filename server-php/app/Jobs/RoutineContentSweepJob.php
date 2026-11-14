<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Carries Claude-routine blog/KB submissions forward: re-vets drafts whose
 * vetting was unavailable (with backoff), retries missing covers hourly,
 * publishes approved items and verifies publications, then lets the bot fix
 * the ones that stopped (RoutineRemediator). Enqueued by reach:schedule
 * every five minutes.
 */
class RoutineContentSweepJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $result = (new RoutineContentPipeline())->sweep((int) ($payload['limit'] ?? 10));
        try {
            // Due schedules the receiver was only told to "schedule" go out now.
            $result['due_schedules_released'] = (new \App\Libraries\Publishing\Jobs\PublicationDeploymentService())->releaseDueSchedules();
        } catch (\Throwable $e) {
            log_message('error', '[RoutineContentSweep] due-schedule release failed: ' . $e->getMessage());
        }

        try {
            // The bot fixes what stopped on the way out: retry, re-vet, or ask
            // the routine for a same-slug rewrite (RoutineRemediator).
            $result['remediation'] = (new \App\Libraries\Automation\Routine\RoutineRemediator())->sweep(5);
        } catch (\Throwable $e) {
            log_message('error', '[RoutineContentSweep] remediation failed: ' . $e->getMessage());
        }

        return $result;
    }
}

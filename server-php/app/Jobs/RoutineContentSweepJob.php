<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Carries Claude-routine blog/KB submissions forward: re-vets drafts whose
 * vetting was unavailable (with backoff), retries missing covers hourly,
 * publishes approved items and verifies publications. Enqueued by
 * reach:schedule every five minutes.
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

        return $result;
    }
}

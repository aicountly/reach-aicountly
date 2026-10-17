<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Intelligence\VisibilityExecutionService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Asks one visibility prompt to one assistant and stores what was found.
 * A provider failure is recorded on the run (status failed + reason).
 */
class AiVisibilityRunJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $runId = (int) ($payload['run_id'] ?? 0);
        if ($runId <= 0) {
            throw new \InvalidArgumentException('run_id is required');
        }

        return (new VisibilityExecutionService())->executeRun($runId);
    }
}

<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Ai\Generation\AiGenerationOrchestrator;

/**
 * Phase 3 — AI Generation Job.
 *
 * Job type key: reach.ai_generation
 *
 * Payload: { "request_id": <int> }
 *
 * Runs entirely through the orchestrator. The job worker picks this up from
 * the Phase 0 PostgreSQL job queue.
 *
 * Invariants:
 * - Never publishes content directly.
 * - Never auto-approves generated content.
 * - Fails cleanly and marks request as 'failed' if the orchestrator throws.
 */
class AiGenerationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $requestId = isset($payload['request_id']) ? (int) $payload['request_id'] : 0;

        if ($requestId <= 0) {
            throw new \InvalidArgumentException('AiGenerationJob: request_id is required.');
        }

        $orchestrator = new AiGenerationOrchestrator();
        $orchestrator->execute($requestId);

        return ['ok' => true, 'request_id' => $requestId];
    }
}

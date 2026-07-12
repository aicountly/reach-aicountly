<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Trivial handler used by tests and admin "dispatch a job" smoke checks.
 * Echoes the payload metadata and asserts basic worker plumbing.
 */
class HealthCheckJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        if (! empty($payload['fail'])) {
            throw new \RuntimeException('HealthCheckJob asked to fail via payload.fail=true');
        }
        return [
            'ok'        => true,
            'checked_at'=> gmdate('c'),
            'attempt'   => $ctx->attempts,
            'worker_id' => $ctx->workerId,
            'echo_keys' => array_keys($payload),
        ];
    }
}

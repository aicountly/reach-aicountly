<?php

namespace App\Libraries;

use App\Jobs\EngagePushRetryJob;
use App\Jobs\HealthCheckJob;
use App\Jobs\MarketingBotDispatchJob;
use RuntimeException;

/**
 * Registry of job type → handler. Bootstrap wires the built-in handlers.
 * Additional handlers can be registered at runtime via `register()`.
 */
class JobHandlerRegistry
{
    /** @var array<string, JobHandlerInterface> */
    private array $handlers = [];

    public function __construct()
    {
        $this->handlers['reach.health_check']          = new HealthCheckJob();
        $this->handlers['reach.marketing_bot_dispatch'] = new MarketingBotDispatchJob();
        $this->handlers['reach.engage_push_retry']      = new EngagePushRetryJob();
    }

    public function register(string $jobType, JobHandlerInterface $handler): void
    {
        $this->handlers[$jobType] = $handler;
    }

    public function has(string $jobType): bool
    {
        return isset($this->handlers[$jobType]);
    }

    public function get(string $jobType): JobHandlerInterface
    {
        if (! isset($this->handlers[$jobType])) {
            throw new RuntimeException("No handler registered for job_type '{$jobType}'");
        }
        return $this->handlers[$jobType];
    }

    /**
     * Execute a reserved job row.
     * @param array<string,mixed> $row  the reserved reach_jobs row
     * @return array<string,mixed>      the handler result
     */
    public function execute(array $row, ?string $workerId = null): array
    {
        $handler = $this->get((string) $row['job_type']);
        $payload = [];
        if (isset($row['payload_json'])) {
            $decoded = is_array($row['payload_json'])
                ? $row['payload_json']
                : json_decode((string) $row['payload_json'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $ctx = JobContext::fromJobRow($row, $workerId ?? (string) ($row['worker_id'] ?? 'unknown'));
        return $handler->handle($payload, $ctx);
    }
}

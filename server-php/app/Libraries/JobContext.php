<?php

namespace App\Libraries;

/**
 * Read-only context handed to each job handler.
 */
final class JobContext
{
    public function __construct(
        public readonly int $jobId,
        public readonly string $jobUuid,
        public readonly string $jobType,
        public readonly string $queue,
        public readonly string $workerId,
        public readonly int $attempts,
        public readonly ?string $requestId,
        public readonly ?string $correlationId,
        public readonly ?int $enqueuedByUserId,
        public readonly ?string $enqueuedActorType,
    ) {}

    /**
     * @param array<string,mixed> $row The `reach_jobs` row from JobService::reserve()
     */
    public static function fromJobRow(array $row, string $workerId): self
    {
        return new self(
            jobId:            (int) $row['id'],
            jobUuid:          (string) ($row['job_uuid'] ?? ''),
            jobType:          (string) ($row['job_type'] ?? ''),
            queue:            (string) ($row['queue'] ?? 'default'),
            workerId:         $workerId,
            attempts:         (int) ($row['attempts'] ?? 0),
            requestId:        $row['request_id']       ?? null,
            correlationId:    $row['correlation_id']   ?? null,
            enqueuedByUserId: isset($row['enqueued_by_user_id']) ? (int) $row['enqueued_by_user_id'] : null,
            enqueuedActorType: $row['enqueued_actor_type'] ?? null,
        );
    }
}

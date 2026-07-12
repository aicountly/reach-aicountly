<?php

namespace App\Libraries;

use App\Models\JobModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * PostgreSQL-backed job queue service.
 *
 * Enqueue paths:
 *   enqueue()         — available immediately (available_at = now())
 *   enqueueAt()       — schedule at a specific UTC timestamp
 *   enqueueDelayed()  — schedule N seconds from now
 *
 * Reservation is atomic and multi-worker safe:
 *   BEGIN;
 *     SELECT id, ... FROM reach_jobs
 *       WHERE queue = $1 AND status = 'pending' AND available_at <= now()
 *       ORDER BY priority DESC, id ASC
 *       FOR UPDATE SKIP LOCKED
 *       LIMIT 1;
 *     UPDATE reach_jobs SET status='processing', reserved_at=..., lease_expires_at=..., worker_id=... WHERE id=$id;
 *   COMMIT;
 *
 * Retry policy: exponential backoff `min(2^attempt * baseSeconds, cap)`.
 * When attempts >= max_attempts the job is marked `dead_letter`.
 */
class JobService
{
    private const DEFAULT_QUEUE      = 'default';
    private const DEFAULT_LEASE_SECS = 300;
    private const BASE_BACKOFF_SECS  = 15;
    private const MAX_BACKOFF_SECS   = 3600;

    private BaseConnection $db;
    private JobModel $model;
    private ?SecretRedactor $redactor;

    public function __construct()
    {
        $this->db    = Database::connect();
        $this->model = new JobModel();
        $this->redactor = class_exists(SecretRedactor::class) ? new SecretRedactor() : null;
    }

    /**
     * @param array<string,mixed>  $payload
     * @param array{
     *   queue?: string,
     *   priority?: int,
     *   max_attempts?: int,
     *   idempotency_key?: string|null,
     *   scheduled_at?: string|null,
     *   available_at?: string|null,
     *   request_id?: string|null,
     *   correlation_id?: string|null,
     *   enqueued_by_user_id?: int|null,
     *   enqueued_actor_type?: string|null,
     *   sensitive?: bool,
     * } $opts
     */
    public function enqueue(string $jobType, array $payload = [], array $opts = []): int
    {
        $now       = date('Y-m-d H:i:s');
        $available = (string) ($opts['available_at'] ?? $opts['scheduled_at'] ?? $now);
        $sensitive = (bool) ($opts['sensitive'] ?? false);

        $storedPayload = $sensitive
            ? ['__sensitive' => true, 'keys' => array_keys($payload)]
            : ($this->redactor ? $this->redactor->redact($payload) : $payload);

        $row = [
            'job_type'            => $jobType,
            'queue'               => (string) ($opts['queue'] ?? self::DEFAULT_QUEUE),
            'status'              => 'pending',
            'priority'            => (int) ($opts['priority'] ?? 0),
            'payload_json'        => json_encode($storedPayload, JSON_UNESCAPED_SLASHES),
            'attempts'            => 0,
            'max_attempts'        => (int) ($opts['max_attempts'] ?? 5),
            'available_at'        => $available,
            'scheduled_at'        => $opts['scheduled_at'] ?? null,
            'idempotency_key'     => $opts['idempotency_key'] ?? null,
            'request_id'          => $opts['request_id'] ?? null,
            'correlation_id'      => $opts['correlation_id'] ?? null,
            'enqueued_by_user_id' => $opts['enqueued_by_user_id'] ?? null,
            'enqueued_actor_type' => $opts['enqueued_actor_type'] ?? 'human',
        ];

        try {
            $this->model->insert($row);
            $id = (int) $this->db->insertID();
        } catch (Throwable $e) {
            if (! empty($opts['idempotency_key'])) {
                $existing = $this->db->table('reach_jobs')
                    ->where('idempotency_key', $opts['idempotency_key'])
                    ->get()->getRowArray();
                if ($existing) {
                    return (int) $existing['id'];
                }
            }
            throw $e;
        }
        $this->audit('job.enqueued', $id, $opts['enqueued_by_user_id'] ?? null, [
            'job_type' => $jobType,
            'queue'    => $row['queue'],
            'available_at' => $available,
        ], $opts['request_id'] ?? null);
        return $id;
    }

    public function enqueueAt(string $jobType, array $payload, string $scheduledAtUtc, array $opts = []): int
    {
        $opts['available_at'] = $scheduledAtUtc;
        $opts['scheduled_at'] = $scheduledAtUtc;
        return $this->enqueue($jobType, $payload, $opts);
    }

    public function enqueueDelayed(string $jobType, array $payload, int $delaySecs, array $opts = []): int
    {
        return $this->enqueueAt(
            $jobType,
            $payload,
            gmdate('Y-m-d H:i:s', time() + max(0, $delaySecs)),
            $opts,
        );
    }

    /**
     * Atomically reserve one job for the given worker. Returns null if none available.
     */
    public function reserve(string $queue, string $workerId, int $leaseSeconds = self::DEFAULT_LEASE_SECS): ?array
    {
        $reserved = null;
        $this->db->transStart();
        try {
            // Postgres-specific FOR UPDATE SKIP LOCKED
            $sql = 'SELECT * FROM reach_jobs
                    WHERE queue = ? AND status = ? AND available_at <= NOW()
                    ORDER BY priority DESC, id ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1';
            $q = $this->db->query($sql, [$queue, 'pending']);
            $row = $q ? $q->getRowArray() : null;

            if ($row) {
                $now = date('Y-m-d H:i:s');
                $exp = gmdate('Y-m-d H:i:s', time() + $leaseSeconds);
                $this->db->table('reach_jobs')->where('id', $row['id'])->update([
                    'status'           => 'processing',
                    'reserved_at'      => $now,
                    'started_at'       => $now,
                    'lease_expires_at' => $exp,
                    'worker_id'        => $workerId,
                    'attempts'         => (int) $row['attempts'] + 1,
                    'updated_at'       => $now,
                ]);
                $row['status']           = 'processing';
                $row['reserved_at']      = $now;
                $row['started_at']       = $now;
                $row['lease_expires_at'] = $exp;
                $row['worker_id']        = $workerId;
                $row['attempts']         = (int) $row['attempts'] + 1;
                $reserved = $row;
                $this->audit('job.reserved', (int) $row['id'], null, [
                    'worker_id' => $workerId,
                    'attempts'  => $row['attempts'],
                    'lease_expires_at' => $exp,
                ], $row['request_id'] ?? null);
            }
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
        $this->db->transComplete();
        return $reserved;
    }

    public function markCompleted(int $jobId, array $result = []): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_jobs')->where('id', $jobId)->update([
            'status'       => 'completed',
            'completed_at' => $now,
            'result_json'  => json_encode(
                $this->redactor ? $this->redactor->redact($result) : $result,
                JSON_UNESCAPED_SLASHES,
            ),
            'error_message'    => null,
            'lease_expires_at' => null,
            'updated_at'   => $now,
        ]);
        $this->audit('job.completed', $jobId, null, ['at' => $now]);
    }

    public function markFailed(int $jobId, string $errorMessage): void
    {
        $row = $this->db->table('reach_jobs')->where('id', $jobId)->get()->getRowArray();
        if (! $row) {
            return;
        }

        $attempts    = (int) $row['attempts'];
        $maxAttempts = (int) $row['max_attempts'];
        $now         = date('Y-m-d H:i:s');
        $err         = substr($errorMessage, 0, 4000);

        if ($attempts >= $maxAttempts) {
            $this->db->table('reach_jobs')->where('id', $jobId)->update([
                'status'           => 'dead_letter',
                'completed_at'     => $now,
                'error_message'    => $err,
                'lease_expires_at' => null,
                'updated_at'       => $now,
            ]);
            $this->audit('job.failed', $jobId, null, [
                'attempts' => $attempts,
                'final'    => true,
                'error'    => $err,
            ]);
            return;
        }

        $backoff = min(
            self::MAX_BACKOFF_SECS,
            self::BASE_BACKOFF_SECS * (2 ** max(0, $attempts - 1)),
        );
        $nextAvailable = gmdate('Y-m-d H:i:s', time() + $backoff);
        $this->db->table('reach_jobs')->where('id', $jobId)->update([
            'status'           => 'pending',
            'error_message'    => $err,
            'available_at'     => $nextAvailable,
            'reserved_at'      => null,
            'started_at'       => null,
            'lease_expires_at' => null,
            'worker_id'        => null,
            'updated_at'       => $now,
        ]);
        $this->audit('job.retried', $jobId, null, [
            'attempts'      => $attempts,
            'error'         => $err,
            'backoff_secs'  => $backoff,
            'available_at'  => $nextAvailable,
        ]);
    }

    public function cancel(int $jobId, ?string $reason = null): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_jobs')->where('id', $jobId)->update([
            'status'           => 'cancelled',
            'completed_at'     => $now,
            'error_message'    => $reason ? substr($reason, 0, 4000) : null,
            'lease_expires_at' => null,
            'updated_at'       => $now,
        ]);
        $this->audit('job.cancelled', $jobId, null, ['reason' => $reason]);
    }

    public function retry(int $jobId): void
    {
        $this->db->table('reach_jobs')->where('id', $jobId)->update([
            'status'           => 'pending',
            'available_at'     => date('Y-m-d H:i:s'),
            'error_message'    => null,
            'completed_at'     => null,
            'reserved_at'      => null,
            'started_at'       => null,
            'lease_expires_at' => null,
            'worker_id'        => null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
        $this->audit('job.retried', $jobId, null, ['source' => 'manual']);
    }

    public function updateProgress(int $jobId, int $percent, ?string $message = null): void
    {
        $this->db->table('reach_jobs')->where('id', $jobId)->update([
            'progress'         => max(0, min(100, $percent)),
            'progress_message' => $message,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Recover leases whose lease_expires_at is in the past by returning them
     * to `pending`. Called by `php spark reach:schedule`.
     *
     * @return int number of recovered jobs
     */
    public function recoverExpiredLeases(): int
    {
        $sql = "UPDATE reach_jobs
                SET status='pending',
                    reserved_at=NULL,
                    lease_expires_at=NULL,
                    worker_id=NULL,
                    started_at=NULL,
                    updated_at=NOW()
                WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()";
        $this->db->query($sql);
        return $this->db->affectedRows();
    }

    /**
     * Prune completed / dead-letter / cancelled jobs older than N days.
     */
    public function pruneOlderThanDays(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $this->db->table('reach_jobs')
            ->whereIn('status', ['completed', 'dead_letter', 'cancelled'])
            ->where('completed_at <', $cutoff)
            ->delete();
        return $this->db->affectedRows();
    }

    /**
     * Best-effort audit write for job lifecycle events. Failures are logged
     * but never bubble up so a failing audit path cannot poison the queue.
     */
    private function audit(string $action, int $jobId, ?int $userId, array $extra = [], ?string $requestId = null): void
    {
        try {
            Services::auditLogger()->log(
                userId:       $userId,
                action:       $action,
                entityType:   'job',
                entityId:     $jobId,
                newValue:     $extra,
                actorType:    $userId ? 'human' : 'system',
                actorService: 'reach:worker',
                requestId:    $requestId,
                jobId:        $jobId,
            );
        } catch (\Throwable $e) {
            log_message('warning', "JobService: audit write for {$action} failed — " . $e->getMessage());
        }
    }
}

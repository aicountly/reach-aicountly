<?php

declare(strict_types=1);

namespace App\Libraries\Jobs;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Operational figures for the reach_jobs queue, and the filters that list
 * exactly the rows behind each figure.
 *
 * The dashboard used to show lifetime totals (QUEUE COMPLETED 70 447,
 * QUEUE FAILED 19 684): mostly the scheduler's own sweeps, plus history from
 * before the 17 Oct schedule-idempotency fix when hourly jobs were enqueued
 * every minute. Clicking the tile opened `?status=failed`, a status the
 * queue never writes (failures retry as `pending`, then `dead_letter`), so
 * the list never matched the number.
 *
 * Every figure here is a window or a live state, and the `filters` next to it
 * are the Job Monitor query string that reproduces it — JobController::index
 * applies them through applyFilters(), the same predicates as the counts.
 */
final class JobQueueInsights
{
    public const FAILED_STATUSES = ['dead_letter', 'failed'];

    /** A ready job waiting longer than this on a queue with no recent completions looks undrained. */
    private const UNDRAINED_AFTER_SECS = 900;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Apply Job Monitor filters to a reach_jobs builder. Keys (all optional):
     *   status      one status or a comma list (e.g. dead_letter,failed)
     *   queue, job_type, worker_id, request_id
     *   since_hours finished/updated within the last N hours
     *   ready=1     pending and available now
     *   scheduled=1 pending, not available yet
     *   running=1   processing with a live lease
     *   stalled=1   processing with an expired lease (worker died)
     *   retrying=1  pending after a failed attempt
     *
     * @param array<string, mixed> $f
     */
    public static function applyFilters(BaseBuilder $b, array $f): BaseBuilder
    {
        $status = trim((string) ($f['status'] ?? ''));
        if ($status !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', $status))));
            count($list) === 1 ? $b->where('status', $list[0]) : $b->whereIn('status', $list);
        }
        foreach (['queue', 'job_type', 'worker_id', 'request_id'] as $key) {
            $v = trim((string) ($f[$key] ?? ''));
            if ($v !== '') {
                $b->where($key, $v);
            }
        }
        $hours = (int) ($f['since_hours'] ?? 0);
        if ($hours > 0) {
            $b->where('COALESCE(completed_at, updated_at) >= NOW() - make_interval(hours => ' . min($hours, 24 * 90) . ')', null, false);
        }
        if (self::flag($f, 'ready')) {
            $b->where('status', 'pending')->where('available_at <= NOW()', null, false);
        }
        if (self::flag($f, 'scheduled')) {
            $b->where('status', 'pending')->where('available_at > NOW()', null, false);
        }
        if (self::flag($f, 'running')) {
            $b->where('status', 'processing')->where('(lease_expires_at IS NULL OR lease_expires_at >= NOW())', null, false);
        }
        if (self::flag($f, 'stalled')) {
            $b->where('status', 'processing')->where('lease_expires_at < NOW()', null, false);
        }
        if (self::flag($f, 'retrying')) {
            $b->where('status', 'pending')->where('attempts >', 0)->where('error_message IS NOT NULL', null, false);
        }

        return $b;
    }

    /**
     * The dashboard's queue block.
     *
     * @return array<string, mixed>
     */
    public function summary(int $hours = 24): array
    {
        $hours = max(1, min(24 * 30, $hours));
        if (! SchemaGuard::hasTable($this->db, 'reach_jobs')) {
            return ['available' => false, 'window_hours' => $hours];
        }

        $row = $this->db->query(
            "SELECT
                COUNT(*) FILTER (WHERE status = 'completed' AND COALESCE(completed_at, updated_at) >= NOW() - make_interval(hours => ?)) AS completed,
                COUNT(*) FILTER (WHERE status IN ('dead_letter','failed') AND COALESCE(completed_at, updated_at) >= NOW() - make_interval(hours => ?)) AS failed,
                COUNT(*) FILTER (WHERE status = 'skipped' AND COALESCE(completed_at, updated_at) >= NOW() - make_interval(hours => ?)) AS skipped,
                COUNT(*) FILTER (WHERE status = 'pending' AND available_at <= NOW()) AS queued,
                COUNT(*) FILTER (WHERE status = 'pending' AND available_at > NOW()) AS scheduled,
                COUNT(*) FILTER (WHERE status = 'pending' AND attempts > 0 AND error_message IS NOT NULL) AS retrying,
                COUNT(*) FILTER (WHERE status = 'processing' AND (lease_expires_at IS NULL OR lease_expires_at >= NOW())) AS running,
                COUNT(*) FILTER (WHERE status = 'processing' AND lease_expires_at < NOW()) AS stalled,
                EXTRACT(EPOCH FROM NOW() - MIN(available_at) FILTER (WHERE status = 'pending' AND available_at <= NOW())) AS oldest_queued_secs
             FROM reach_jobs",
            [$hours, $hours, $hours]
        )->getRowArray() ?? [];

        $since = 'since_hours=' . $hours;

        return [
            'available'           => true,
            'window_hours'        => $hours,
            'completed'           => (int) ($row['completed'] ?? 0),
            'failed'              => (int) ($row['failed'] ?? 0),
            'skipped'             => (int) ($row['skipped'] ?? 0),
            'queued'              => (int) ($row['queued'] ?? 0),
            'scheduled'           => (int) ($row['scheduled'] ?? 0),
            'retrying'            => (int) ($row['retrying'] ?? 0),
            'running'             => (int) ($row['running'] ?? 0),
            'stalled'             => (int) ($row['stalled'] ?? 0),
            'oldest_queued_secs'  => isset($row['oldest_queued_secs']) && $row['oldest_queued_secs'] !== null
                ? (int) round((float) $row['oldest_queued_secs']) : null,
            'queues'              => $this->queues($hours),
            'failing_types'       => $this->failingTypes($hours),
            // The Job Monitor query string behind each figure.
            'filters'             => [
                'completed' => "status=completed&{$since}",
                'failed'    => "status=dead_letter,failed&{$since}",
                'skipped'   => "status=skipped&{$since}",
                'queued'    => 'ready=1',
                'scheduled' => 'scheduled=1',
                'retrying'  => 'retrying=1',
                'running'   => 'running=1',
                'stalled'   => 'stalled=1',
            ],
        ];
    }

    /**
     * Per-queue backlog. `undrained` flags a queue whose ready jobs have
     * waited over 15 minutes with nothing completed on it for an hour —
     * almost always a queue missing from the worker's --queue list.
     *
     * @return list<array<string, mixed>>
     */
    public function queues(int $hours = 24): array
    {
        $rows = $this->db->query(
            "SELECT queue,
                COUNT(*) FILTER (WHERE status = 'pending' AND available_at <= NOW()) AS queued,
                COUNT(*) FILTER (WHERE status = 'processing') AS running,
                COUNT(*) FILTER (WHERE status = 'completed' AND COALESCE(completed_at, updated_at) >= NOW() - make_interval(hours => ?)) AS completed,
                COUNT(*) FILTER (WHERE status IN ('dead_letter','failed') AND COALESCE(completed_at, updated_at) >= NOW() - make_interval(hours => ?)) AS failed,
                COUNT(*) FILTER (WHERE status IN ('completed','skipped','dead_letter') AND COALESCE(completed_at, updated_at) >= NOW() - INTERVAL '1 hour') AS finished_1h,
                EXTRACT(EPOCH FROM NOW() - MIN(available_at) FILTER (WHERE status = 'pending' AND available_at <= NOW())) AS oldest_queued_secs
             FROM reach_jobs
             WHERE status IN ('pending','processing')
                OR COALESCE(completed_at, updated_at) >= NOW() - make_interval(hours => ?)
             GROUP BY queue
             ORDER BY queue",
            [$hours, $hours, $hours]
        )->getResultArray();

        return array_map(static function (array $r): array {
            $oldest = $r['oldest_queued_secs'] !== null ? (int) round((float) $r['oldest_queued_secs']) : null;

            return [
                'queue'              => (string) $r['queue'],
                'queued'             => (int) $r['queued'],
                'running'            => (int) $r['running'],
                'completed'          => (int) $r['completed'],
                'failed'             => (int) $r['failed'],
                'oldest_queued_secs' => $oldest,
                'undrained'          => $oldest !== null && $oldest > self::UNDRAINED_AFTER_SECS && (int) $r['finished_1h'] === 0,
                'filters'            => 'ready=1&queue=' . rawurlencode((string) $r['queue']),
            ];
        }, $rows);
    }

    /**
     * Job types that failed (dead letter) or are retrying after a failure in
     * the window, with the latest error message per type.
     *
     * @return list<array<string, mixed>>
     */
    public function failingTypes(int $hours = 24, int $limit = 10): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_jobs')) {
            return [];
        }
        $rows = $this->db->query(
            "WITH f AS (
                SELECT job_type, status, error_message, COALESCE(completed_at, updated_at) AS at
                  FROM reach_jobs
                 WHERE (status IN ('dead_letter','failed') AND COALESCE(completed_at, updated_at) >= NOW() - make_interval(hours => ?))
                    OR (status = 'pending' AND attempts > 0 AND error_message IS NOT NULL AND updated_at >= NOW() - make_interval(hours => ?))
             )
             SELECT job_type,
                    COUNT(*) FILTER (WHERE status <> 'pending') AS failed,
                    COUNT(*) FILTER (WHERE status = 'pending')  AS retrying,
                    MAX(at) AS last_at,
                    (ARRAY_AGG(error_message ORDER BY at DESC))[1] AS last_error
               FROM f
              GROUP BY job_type
              ORDER BY failed DESC, retrying DESC, job_type
              LIMIT ?",
            [$hours, $hours, max(1, $limit)]
        )->getResultArray();

        return array_map(static fn (array $r): array => [
            'job_type'   => (string) $r['job_type'],
            'failed'     => (int) $r['failed'],
            'retrying'   => (int) $r['retrying'],
            'last_at'    => $r['last_at'],
            'last_error' => $r['last_error'] !== null ? mb_substr((string) $r['last_error'], 0, 500) : null,
            'filters'    => 'status=dead_letter,failed&since_hours=' . $hours . '&job_type=' . rawurlencode((string) $r['job_type']),
        ], $rows);
    }

    /** @param array<string, mixed> $f */
    private static function flag(array $f, string $key): bool
    {
        return in_array((string) ($f[$key] ?? ''), ['1', 'true', 'yes'], true);
    }
}

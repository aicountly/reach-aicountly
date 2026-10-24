<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Jobs\JobQueueInsights;
use App\Models\JobModel;
use Config\Services;

/**
 * Admin API for the reach_jobs queue.
 *
 * Payload redaction: `payload_json` and `result_json` are only ever returned
 * as summaries (keys + a hash) unless the requester holds `super_admin`
 * AND explicitly passes `?include=payload`.
 */
class JobController extends BaseApiController
{
    /**
     * Filters (all optional) are JobQueueInsights::applyFilters(): status
     * (one or a comma list), queue, job_type, worker_id, request_id,
     * since_hours, ready, scheduled, running, stalled, retrying — the same
     * predicates the dashboard counts with, so a tile's number and the list
     * it opens always agree.
     */
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();

        $filters = [];
        foreach (['status', 'queue', 'job_type', 'worker_id', 'request_id', 'since_hours', 'ready', 'scheduled', 'running', 'stalled', 'retrying'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $filters[$f] = $v;
            }
        }

        $builder = JobQueueInsights::applyFilters(\Config\Database::connect()->table('reach_jobs'), $filters);
        $total   = $builder->countAllResults(false);
        $rows    = $builder->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        $summarized = array_map(fn ($row) => $this->summarize($row, false), $rows);
        return $this->ok([
            'items'   => $summarized,
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
            'filters' => $filters,
        ]);
    }

    public function show(int $id)
    {
        $row = (new JobModel())->find($id);
        if (! $row) {
            return $this->fail('Job not found.', 404);
        }
        $includePayload = (string) $this->request->getGet('include') === 'payload'
            && ($this->user()['role'] ?? '') === 'super_admin';

        return $this->ok($this->summarize($row, $includePayload) + [
            'siblings' => $this->siblings($row),
            'history'  => $this->history($id),
        ]);
    }

    /**
     * Other jobs enqueued by the same request (one click can fan out several),
     * newest first. Summaries only — same redaction as the list.
     *
     * @return list<array<string, mixed>>
     */
    private function siblings(array $row): array
    {
        $requestId = trim((string) ($row['request_id'] ?? ''));
        if ($requestId === '') {
            return [];
        }
        $rows = \Config\Database::connect()->table('reach_jobs')
            ->where('request_id', $requestId)
            ->where('id !=', (int) $row['id'])
            ->orderBy('id', 'DESC')->limit(50)
            ->get()->getResultArray();

        return array_map(fn (array $r): array => $this->summarize($r, false), $rows);
    }

    /**
     * Audit events about this job (retries, cancels) and those it produced
     * (reach_audit_logs.job_id). The queue keeps only the latest attempt's
     * error on the row, so this is the only per-attempt trail there is.
     *
     * @return list<array<string, mixed>>
     */
    private function history(int $id): array
    {
        try {
            $db = \Config\Database::connect();
            $hasJobId = \App\Libraries\Database\SchemaGuard::hasColumn($db, 'reach_audit_logs', 'job_id');
            $builder  = $db->table('reach_audit_logs')
                ->select('id, action, entity_type, entity_id, user_id, old_value, new_value, created_at' . ($hasJobId ? ', job_id, actor_type, reason' : ''))
                ->groupStart()
                    ->groupStart()->where('entity_type', 'job')->where('entity_id', $id)->groupEnd();
            if ($hasJobId) {
                $builder->orWhere('job_id', $id);
            }
            $rows = $builder->groupEnd()->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'JobController::history: ' . $e->getMessage());
            return [];
        }

        return array_map(static function (array $r): array {
            foreach (['old_value', 'new_value'] as $k) {
                if (isset($r[$k]) && is_string($r[$k])) {
                    $r[$k] = json_decode($r[$k], true);
                }
            }
            $r['id'] = (int) $r['id'];

            return $r;
        }, $rows);
    }

    public function retry(int $id)
    {
        $model = new JobModel();
        $row = $model->find($id);
        if (! $row) {
            return $this->fail('Job not found.', 404);
        }
        if (! in_array($row['status'], ['failed', 'dead_letter', 'cancelled', 'skipped'], true)) {
            return $this->fail('Only failed / dead_letter / cancelled / skipped jobs may be retried.', 422);
        }
        Services::jobService()->retry($id);
        Services::auditLogger()->log(
            userId: $this->userId(),
            action: 'job.retried',
            entityType: 'job',
            entityId: $id,
            oldValue: ['status' => $row['status']],
            newValue: ['status' => 'pending'],
        );
        return $this->ok(['id' => $id, 'status' => 'pending']);
    }

    public function cancel(int $id)
    {
        $model = new JobModel();
        $row = $model->find($id);
        if (! $row) {
            return $this->fail('Job not found.', 404);
        }
        // A dead-lettered job can be cancelled too: it will never run again on
        // its own, but it stays in the failure counts (the daily report counts
        // every dead letter) until someone closes it. Cancelling records that
        // it was looked at and needs nothing more.
        if (in_array($row['status'], ['completed', 'cancelled', 'skipped'], true)) {
            return $this->fail('Job cannot be cancelled in its current state.', 422);
        }
        $reason = trim((string) ($this->input()['reason'] ?? ''));
        Services::jobService()->cancel($id, $reason !== '' ? $reason : null);
        Services::auditLogger()->log(
            userId: $this->userId(),
            action: 'job.cancelled',
            entityType: 'job',
            entityId: $id,
            oldValue: ['status' => $row['status']],
            newValue: ['status' => 'cancelled', 'reason' => $reason],
        );
        return $this->ok(['id' => $id, 'status' => 'cancelled']);
    }

    /**
     * Return a safe representation of a job row, redacting payload / result
     * to a keys-and-hash summary unless the caller explicitly requested the
     * full payload AND holds super_admin.
     */
    private function summarize(array $row, bool $includePayload): array
    {
        $payloadKeys = [];
        $payloadHash = null;
        if (! empty($row['payload_json'])) {
            $decoded = is_array($row['payload_json'])
                ? $row['payload_json']
                : json_decode((string) $row['payload_json'], true);
            if (is_array($decoded)) {
                $payloadKeys = array_keys($decoded);
                $payloadHash = substr(hash('sha256', json_encode($decoded)), 0, 12);
            }
        }

        $summary = [
            'id'                  => (int) $row['id'],
            'job_uuid'            => (string) ($row['job_uuid'] ?? ''),
            'job_type'            => (string) $row['job_type'],
            'queue'               => (string) $row['queue'],
            'status'              => (string) $row['status'],
            'priority'            => (int) $row['priority'],
            'attempts'            => (int) $row['attempts'],
            'max_attempts'        => (int) $row['max_attempts'],
            'progress'            => isset($row['progress']) ? (int) $row['progress'] : null,
            'progress_message'    => $row['progress_message'] ?? null,
            'available_at'        => $row['available_at']  ?? null,
            'scheduled_at'        => $row['scheduled_at']  ?? null,
            'reserved_at'         => $row['reserved_at']   ?? null,
            'started_at'          => $row['started_at']    ?? null,
            'completed_at'        => $row['completed_at']  ?? null,
            'lease_expires_at'    => $row['lease_expires_at'] ?? null,
            'worker_id'           => $row['worker_id']     ?? null,
            'error_message'       => $row['error_message'] ?? null,
            'request_id'          => $row['request_id']    ?? null,
            'correlation_id'      => $row['correlation_id']?? null,
            'enqueued_by_user_id' => isset($row['enqueued_by_user_id']) ? (int) $row['enqueued_by_user_id'] : null,
            'enqueued_actor_type' => $row['enqueued_actor_type'] ?? null,
            'created_at'          => $row['created_at'] ?? null,
            'updated_at'          => $row['updated_at'] ?? null,
            'payload_summary'     => [
                'keys' => $payloadKeys,
                'hash' => $payloadHash,
            ],
        ];
        if ($includePayload) {
            $summary['payload'] = is_array($row['payload_json'])
                ? $row['payload_json']
                : json_decode((string) $row['payload_json'], true);
            $summary['result'] = is_array($row['result_json'] ?? null)
                ? $row['result_json']
                : json_decode((string) ($row['result_json'] ?? 'null'), true);
        }
        return $summary;
    }
}

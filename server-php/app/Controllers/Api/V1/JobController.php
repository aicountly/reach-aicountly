<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
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
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $model = new JobModel();

        foreach (['status', 'queue', 'job_type', 'worker_id'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $model->where($f, $v);
            }
        }
        $rid = trim((string) $this->request->getGet('request_id'));
        if ($rid !== '') {
            $model->where('request_id', $rid);
        }

        $total = $model->countAllResults(false);
        $rows  = $model
            ->orderBy('id', 'DESC')
            ->findAll($limit, $offset);

        $summarized = array_map(fn ($row) => $this->summarize($row, false), $rows);
        return $this->ok([
            'items' => $summarized,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
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
        return $this->ok($this->summarize($row, $includePayload));
    }

    public function retry(int $id)
    {
        $model = new JobModel();
        $row = $model->find($id);
        if (! $row) {
            return $this->fail('Job not found.', 404);
        }
        if (! in_array($row['status'], ['failed', 'dead_letter', 'cancelled'], true)) {
            return $this->fail('Only failed / dead_letter / cancelled jobs may be retried.', 422);
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
        if (in_array($row['status'], ['completed', 'dead_letter', 'cancelled'], true)) {
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

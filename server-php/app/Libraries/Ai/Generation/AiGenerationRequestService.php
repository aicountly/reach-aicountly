<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

/**
 * Phase 3 — CRUD operations for AI generation requests.
 *
 * A generation request is the business-level record. Individual provider
 * attempts are represented by generation runs.
 */
class AiGenerationRequestService
{
    /**
     * Create a new generation request.
     * Returns the inserted row.
     */
    public function create(array $data, array $actor): array
    {
        $db = db_connect();

        // Idempotency: if key already exists, return existing record
        if (! empty($data['idempotency_key'])) {
            $existing = $db->table('reach_ai_generation_requests')
                ->where('idempotency_key', $data['idempotency_key'])
                ->get()
                ->getRowArray();

            if ($existing) {
                return $existing;
            }
        }

        $row = [
            'task_type'               => $data['task_type'],
            'content_type'            => $data['content_type'],
            'status'                  => 'pending',
            'priority'                => (int) ($data['priority'] ?? 0),
            'request_parameters_json' => json_encode($data['parameters'] ?? []),
            'requested_actor_type'    => $actor['type'] ?? 'human',
            'requested_by_user_id'    => $actor['user_id'] ?? null,
            'request_id'              => $data['request_id'] ?? null,
            'idempotency_key'         => $data['idempotency_key'] ?? null,
            'content_item_id'         => $data['content_item_id'] ?? null,
            'daily_pack_id'           => $data['daily_pack_id'] ?? null,
            'prompt_version_id'       => $data['prompt_version_id'] ?? null,
            'requested_provider_id'   => $data['provider_id'] ?? null,
            'requested_model_id'      => $data['model_id'] ?? null,
            'created_at'              => date('Y-m-d H:i:s'),
            'updated_at'              => date('Y-m-d H:i:s'),
        ];

        $db->table('reach_ai_generation_requests')->insert($row);
        $id = (int) $db->insertID();

        return $this->findById($id);
    }

    public function updateStatus(int $id, string $status, array $extras = []): void
    {
        db_connect()->table('reach_ai_generation_requests')->update(
            array_merge(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $extras),
            ['id' => $id]
        );
    }

    public function linkJob(int $requestId, int $jobId): void
    {
        $this->updateStatus($requestId, 'queued', ['job_id' => $jobId]);
    }

    public function cancel(int $id, string $reason = ''): void
    {
        $this->updateStatus($id, 'cancelled', ['cancelled_at' => date('Y-m-d H:i:s')]);
    }

    public function findById(int $id): array
    {
        $row = db_connect()
            ->table('reach_ai_generation_requests')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Generation request #{$id} not found.");
        }

        return $row;
    }

    public function findByUuid(string $uuid): array
    {
        $row = db_connect()
            ->table('reach_ai_generation_requests')
            ->where('uuid', $uuid)
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Generation request '{$uuid}' not found.");
        }

        return $row;
    }
}

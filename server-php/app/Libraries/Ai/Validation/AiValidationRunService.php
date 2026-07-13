<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation;

/**
 * Phase 3 — Manages AI validation run records.
 */
class AiValidationRunService
{
    public function create(int $contentItemId, int $contentVersionId, ?int $generationRequestId = null, array $actor = []): array
    {
        $db = db_connect();
        $db->table('reach_ai_validation_runs')->insert([
            'generation_request_id' => $generationRequestId,
            'content_item_id'       => $contentItemId,
            'content_version_id'    => $contentVersionId,
            'status'                => 'pending',
            'created_actor_type'    => $actor['type'] ?? 'system',
            'created_by_user_id'    => $actor['user_id'] ?? null,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        return $this->findById((int) $db->insertID());
    }

    public function markRunning(int $id): void
    {
        db_connect()->table('reach_ai_validation_runs')->update(
            ['status' => 'running', 'started_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
    }

    public function markCompleted(int $id, int $blocking, int $critical, int $warnings, int $info): void
    {
        db_connect()->table('reach_ai_validation_runs')->update([
            'status'         => 'completed',
            'blocking_count' => $blocking,
            'critical_count' => $critical,
            'warning_count'  => $warnings,
            'info_count'     => $info,
            'completed_at'   => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function findById(int $id): array
    {
        $row = db_connect()
            ->table('reach_ai_validation_runs')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Validation run #{$id} not found.");
        }

        return $row;
    }
}

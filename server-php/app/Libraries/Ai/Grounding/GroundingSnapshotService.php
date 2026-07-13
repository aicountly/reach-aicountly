<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Grounding;

/**
 * Phase 3 — Persists and retrieves grounding snapshots.
 *
 * Snapshots are immutable once created. They are linked to a generation_request_id.
 */
class GroundingSnapshotService
{
    private AiGroundingContextBuilder $builder;

    public function __construct(?AiGroundingContextBuilder $builder = null)
    {
        $this->builder = $builder ?? new AiGroundingContextBuilder();
    }

    /**
     * Build and persist a snapshot for a generation request.
     * Returns the inserted snapshot row.
     */
    public function createForRequest(
        int $generationRequestId,
        array $groundingContext,
    ): array {
        $db = db_connect();

        $record = $this->builder->prepareSnapshot($groundingContext, $generationRequestId);

        $db->table('reach_ai_grounding_snapshots')->insert($record);
        $id = (int) $db->insertID();

        return $this->findById($id);
    }

    /**
     * Find an existing snapshot by generation request ID.
     * Returns null if not yet created.
     */
    public function findByRequestId(int $requestId): ?array
    {
        $row = db_connect()
            ->table('reach_ai_grounding_snapshots')
            ->where('generation_request_id', $requestId)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    public function findById(int $id): array
    {
        $row = db_connect()
            ->table('reach_ai_grounding_snapshots')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Grounding snapshot #{$id} not found.");
        }

        return $row;
    }
}

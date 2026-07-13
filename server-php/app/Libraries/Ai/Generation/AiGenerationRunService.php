<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

use App\Libraries\Ai\AiGenerationResult;
use App\Libraries\Ai\AiProviderError;

/**
 * Phase 3 — Manages individual provider attempt records.
 */
class AiGenerationRunService
{
    public function create(int $requestId, int $providerId, int $modelId, int $attemptNumber, ?int $promptVersionId = null): array
    {
        $db = db_connect();
        $db->table('reach_ai_generation_runs')->insert([
            'generation_request_id' => $requestId,
            'attempt_number'        => $attemptNumber,
            'provider_id'           => $providerId,
            'model_id'              => $modelId,
            'prompt_version_id'     => $promptVersionId,
            'status'                => 'pending',
            'created_at'            => date('Y-m-d H:i:s'),
        ]);
        return $this->findById((int) $db->insertID());
    }

    public function markRunning(int $id): void
    {
        db_connect()->table('reach_ai_generation_runs')->update(
            ['status' => 'running', 'started_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
    }

    public function markCompleted(int $id, AiGenerationResult $result): void
    {
        db_connect()->table('reach_ai_generation_runs')->update([
            'status'               => 'completed',
            'completed_at'         => date('Y-m-d H:i:s'),
            'duration_ms'          => $result->durationMs,
            'provider_response_id' => $result->providerResponseId,
            'input_tokens'         => $result->inputTokens,
            'output_tokens'        => $result->outputTokens,
            'total_tokens'         => $result->totalTokens,
            'response_hash'        => hash('sha256', $result->rawContent),
        ], ['id' => $id]);
    }

    public function markFailed(int $id, AiProviderError $error, int $durationMs = 0): void
    {
        db_connect()->table('reach_ai_generation_runs')->update([
            'status'               => 'failed',
            'completed_at'         => date('Y-m-d H:i:s'),
            'duration_ms'          => $durationMs,
            'retryable_error'      => $error->isRetryable(),
            'error_category'       => $error->category,
            'redacted_error_message' => $error->message,
        ], ['id' => $id]);
    }

    public function linkGroundingSnapshot(int $runId, int $snapshotId): void
    {
        db_connect()->table('reach_ai_generation_runs')->update(
            ['grounding_snapshot_id' => $snapshotId],
            ['id' => $runId]
        );
    }

    public function findById(int $id): array
    {
        $row = db_connect()
            ->table('reach_ai_generation_runs')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Generation run #{$id} not found.");
        }

        return $row;
    }

    public function latestForRequest(int $requestId): ?array
    {
        return db_connect()
            ->table('reach_ai_generation_runs')
            ->where('generation_request_id', $requestId)
            ->orderBy('attempt_number', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray() ?: null;
    }

    public function countAttemptsForRequest(int $requestId): int
    {
        return (int) db_connect()
            ->table('reach_ai_generation_runs')
            ->where('generation_request_id', $requestId)
            ->countAllResults();
    }
}

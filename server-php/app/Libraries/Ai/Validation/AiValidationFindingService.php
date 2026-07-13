<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation;

/**
 * Phase 3 — Persists validation findings.
 * Human approvers may waive findings with a reason; AI must never auto-waive.
 */
class AiValidationFindingService
{
    public function store(int $runId, ValidationFinding $finding): array
    {
        $db = db_connect();
        $db->table('reach_ai_validation_findings')->insert([
            'validation_run_id'  => $runId,
            'validator_type'     => $finding->validatorType,
            'is_ai_assisted'     => $finding->isAiAssisted,
            'severity'           => $finding->severity,
            'status'             => $finding->status,
            'title'              => $finding->title,
            'message'            => $finding->message,
            'affected_field'     => $finding->affectedField,
            'details_json'       => $finding->details ? json_encode($finding->details) : null,
            'suggested_fix'      => $finding->suggestedFix,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        return $this->findById((int) $db->insertID());
    }

    public function storeBatch(int $runId, array $findings): array
    {
        return array_map(fn($f) => $this->store($runId, $f), $findings);
    }

    /**
     * Only a human actor may waive a finding.
     * AI must NEVER call this method.
     */
    public function waive(int $findingId, string $reason, array $actor): array
    {
        if (($actor['type'] ?? 'human') === 'ai') {
            throw new \RuntimeException('AI must not waive validation findings.');
        }

        db_connect()->table('reach_ai_validation_findings')->update([
            'status'          => 'waived',
            'waiver_reason'   => $reason,
            'waived_by_user_id' => $actor['user_id'] ?? null,
            'waived_at'       => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ], ['id' => $findingId]);

        return $this->findById($findingId);
    }

    public function findById(int $id): array
    {
        $row = db_connect()
            ->table('reach_ai_validation_findings')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Validation finding #{$id} not found.");
        }

        return $row;
    }

    public function findByRunId(int $runId): array
    {
        return db_connect()
            ->table('reach_ai_validation_findings')
            ->where('validation_run_id', $runId)
            ->orderBy('severity', 'DESC')
            ->get()
            ->getResultArray();
    }
}

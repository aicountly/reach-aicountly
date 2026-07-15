<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AiVisibilityObservationModel extends Model
{
    protected $table      = 'reach_ai_visibility_observations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'response_id', 'run_id', 'entity_mentioned', 'mention_type',
        'mention_order', 'sentiment_classification', 'coverage_state', 'confidence',
        'evidence_excerpt', 'parser_finding', 'created_at',
    ];

    public function getForRun(int $runId): array
    {
        return $this->where('run_id', $runId)->findAll();
    }

    public function getByCoverage(int $tenantId, string $coverageState): array
    {
        return $this->select('o.*')
                    ->join('reach_ai_visibility_runs r', 'r.id = o.run_id')
                    ->where('r.tenant_id', $tenantId)
                    ->where('o.coverage_state', $coverageState)
                    ->findAll();
    }

    public function getForPromptInPeriod(int $promptId, int $tenantId): array
    {
        return $this->db->query(
            "SELECT o.*
             FROM reach_ai_visibility_observations o
             JOIN reach_ai_visibility_runs r ON r.id = o.run_id
             JOIN reach_ai_visibility_prompt_versions pv ON pv.id = r.prompt_version_id
             WHERE pv.prompt_id = ? AND r.tenant_id = ?",
            [$promptId, $tenantId]
        )->getResultArray();
    }
}

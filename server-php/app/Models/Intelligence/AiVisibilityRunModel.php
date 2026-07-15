<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AiVisibilityRunModel extends Model
{
    protected $table      = 'reach_ai_visibility_runs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'tenant_id', 'prompt_version_id', 'run_type', 'ai_route', 'ai_model',
        'ai_provider', 'status', 'execution_budget_cents', 'actual_cost_cents',
        'tokens_used', 'job_id', 'queued_at', 'started_at', 'completed_at', 'error_message',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getRunsForPrompt(int $promptVersionId, int $limit = 20): array
    {
        return $this->where('prompt_version_id', $promptVersionId)
                    ->orderBy('queued_at', 'DESC')
                    ->findAll($limit);
    }
}

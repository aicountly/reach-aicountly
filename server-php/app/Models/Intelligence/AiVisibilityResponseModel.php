<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AiVisibilityResponseModel extends Model
{
    protected $table      = 'reach_ai_visibility_responses';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'run_id', 'raw_response', 'response_timestamp', 'parser_version',
        'parse_status', 'tokens_used', 'token_breakdown', 'retention_expires_at', 'created_at',
    ];

    public function findByRunId(int $runId): ?array
    {
        return $this->where('run_id', $runId)->first();
    }
}

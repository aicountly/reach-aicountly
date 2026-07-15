<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshBriefModel extends Model
{
    protected $table      = 'reach_refresh_briefs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'workflow_id', 'evidence_snapshot_id', 'refresh_objective',
        'key_changes', 'target_sections', 'source_requirements', 'created_by',
    ];

    public function getForWorkflow(int $workflowId): ?array
    {
        return $this->where('workflow_id', $workflowId)->first();
    }
}

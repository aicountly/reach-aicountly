<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class ReadinessFindingModel extends Model
{
    protected $table      = 'reach_readiness_findings';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'audit_run_id', 'severity', 'category', 'title', 'description',
        'affected_component', 'resolution_status', 'accepted_risk_reason',
        'accepted_by', 'accepted_at', 'resolved_at',
    ];

    public function getOpenBlockers(): array
    {
        return $this->whereIn('severity', ['critical', 'high'])
                    ->whereIn('resolution_status', ['open', 'in_progress'])
                    ->findAll();
    }
}

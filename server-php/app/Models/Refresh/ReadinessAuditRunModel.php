<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class ReadinessAuditRunModel extends Model
{
    protected $table      = 'reach_readiness_audit_runs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'tenant_id', 'audit_type', 'status', 'started_at', 'completed_at', 'triggered_by',
    ];
}

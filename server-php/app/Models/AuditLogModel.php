<?php

namespace App\Models;

use CodeIgniter\Model;

class AuditLogModel extends Model
{
    protected $table         = 'reach_audit_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'user_id', 'actor_type', 'actor_service',
        'action', 'entity_type', 'entity_id',
        'old_value', 'new_value', 'metadata',
        'reason', 'request_id', 'job_id',
        'ip_address', 'user_agent', 'created_at',
    ];
}

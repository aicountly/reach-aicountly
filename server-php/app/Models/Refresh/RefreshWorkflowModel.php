<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshWorkflowModel extends Model
{
    protected $table      = 'reach_refresh_workflows';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'recommendation_id', 'content_identity_id', 'status',
        'lock_version', 'refresh_objective', 'risk_classification', 'assigned_to',
        'due_date', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getForTenant(int $tenantId, string $status = null): array
    {
        $q = $this->where('tenant_id', $tenantId);
        if ($status) $q->where('status', $status);
        return $q->orderBy('created_at', 'DESC')->findAll();
    }
}

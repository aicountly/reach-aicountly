<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshPolicyModel extends Model
{
    protected $table      = 'reach_refresh_policies';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'name', 'content_type', 'is_active', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getActiveForTenant(int $tenantId, string $contentType = null): array
    {
        $q = $this->where('tenant_id', $tenantId)->where('is_active', true);
        if ($contentType) $q->where('content_type', $contentType);
        return $q->findAll();
    }
}

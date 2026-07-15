<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class CompetitorModel extends Model
{
    protected $table      = 'reach_competitors';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'name', 'legal_name', 'website_domain', 'category',
        'monitoring_enabled', 'monitoring_status', 'effective_from', 'effective_to',
        'notes', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getActiveForTenant(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)
                    ->where('monitoring_status', 'active')
                    ->where('monitoring_enabled', true)
                    ->findAll();
    }
}

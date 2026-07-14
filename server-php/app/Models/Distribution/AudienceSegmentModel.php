<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class AudienceSegmentModel extends Model
{
    protected $table      = 'reach_audience_segments';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'name', 'description', 'segment_type',
        'criteria_summary', 'estimated_count', 'is_active', 'created_by',
    ];

    public function findByUuid(string $uuid, int $tenantId): ?array
    {
        return $this->where('uuid', $uuid)->where('tenant_id', $tenantId)->first();
    }

    public function listForTenant(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name', 'ASC')->findAll();
    }
}

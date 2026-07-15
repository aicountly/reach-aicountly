<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class UtmTemplateModel extends Model
{
    protected $table      = 'reach_utm_templates';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'name', 'description', 'utm_source', 'utm_medium',
        'utm_campaign_template', 'utm_content_template', 'utm_term_template',
        'channel_hint', 'is_active', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getActiveForTenant(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)->where('is_active', true)->findAll();
    }
}

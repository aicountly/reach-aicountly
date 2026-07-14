<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignTemplateModel extends Model
{
    protected $table      = 'reach_campaign_templates';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'channel', 'name', 'provider_template_id',
        'language', 'approval_status', 'is_active', 'created_by',
    ];

    public function findByUuid(string $uuid, int $tenantId): ?array
    {
        return $this->where('uuid', $uuid)->where('tenant_id', $tenantId)->first();
    }
}

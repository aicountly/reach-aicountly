<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class AttributionModelModel extends Model
{
    protected $table      = 'reach_attribution_models';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'tenant_id', 'model_name', 'description', 'formula',
        'lookback_window_days', 'limitations', 'is_active',
    ];

    public function getActiveForTenant(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)->where('is_active', true)->findAll();
    }
}

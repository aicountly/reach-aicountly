<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AttributionCalculationVersionModel extends Model
{
    protected $table      = 'reach_attribution_calculation_versions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'tenant_id', 'version_number', 'calculated_at', 'method',
        'period_from', 'period_to', 'total_conversions', 'attributed_count',
        'unattributed_count', 'calculation_params', 'triggered_by',
    ];
}

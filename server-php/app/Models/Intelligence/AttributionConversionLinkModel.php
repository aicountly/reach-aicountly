<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class AttributionConversionLinkModel extends Model
{
    protected $table      = 'reach_attribution_conversion_links';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'lead_id', 'first_touchpoint_id', 'last_touchpoint_id',
        'conversion_type', 'converted_at', 'matching_method', 'confidence_state',
        'calculation_version_id', 'manual_correction_note', 'corrected_by', 'corrected_at',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class AttributionJourneyCalculationModel extends Model
{
    protected $table      = 'reach_attribution_journey_calculations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'tenant_id', 'conversion_link_id', 'model_version_id',
        'ordered_touchpoint_ids', 'total_touchpoints', 'identity_confidence',
        'completeness_score', 'limitations_note', 'calculated_at',
    ];
}

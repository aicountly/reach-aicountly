<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class AttributionAllocationFactModel extends Model
{
    protected $table      = 'reach_attribution_allocation_facts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'journey_calculation_id', 'touchpoint_id', 'touch_position',
        'allocation_weight', 'model_name', 'model_version',
    ];

    public function getForJourney(int $journeyId): array
    {
        return $this->where('journey_calculation_id', $journeyId)
                    ->orderBy('touch_position', 'ASC')
                    ->findAll();
    }
}

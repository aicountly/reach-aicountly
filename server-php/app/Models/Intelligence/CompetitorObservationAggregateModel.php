<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class CompetitorObservationAggregateModel extends Model
{
    protected $table      = 'reach_competitor_observation_aggregates';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'competitor_id', 'prompt_id', 'tenant_id', 'period_start', 'period_end',
        'total_runs', 'mention_count', 'citation_count', 'mention_rate',
        'avg_mention_order', 'sample_scope_note', 'computed_at',
    ];
}

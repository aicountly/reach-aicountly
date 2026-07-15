<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshScoreComponentModel extends Model
{
    protected $table      = 'reach_refresh_score_components';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'recommendation_id', 'factor', 'raw_value', 'weight',
        'contribution', 'evidence_source', 'evidence_period', 'scoring_version',
    ];

    public function getForRecommendation(int $recommendationId): array
    {
        return $this->where('recommendation_id', $recommendationId)->findAll();
    }
}

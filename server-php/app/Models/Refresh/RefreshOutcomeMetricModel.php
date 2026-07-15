<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshOutcomeMetricModel extends Model
{
    protected $table      = 'reach_refresh_outcome_metrics';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'outcome_window_id', 'metric_domain', 'metric_name', 'baseline_value',
        'post_value', 'observed_change_pct', 'evidence_source', 'confidence',
        'data_points_baseline', 'data_points_post', 'measured_at',
    ];

    public function getForWindow(int $windowId): array
    {
        return $this->where('outcome_window_id', $windowId)->findAll();
    }
}

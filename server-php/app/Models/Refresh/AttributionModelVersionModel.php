<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class AttributionModelVersionModel extends Model
{
    protected $table      = 'reach_attribution_model_versions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'model_id', 'version_number', 'formula', 'weight_rules', 'approved_by', 'approved_at',
    ];

    public function getLatest(int $modelId): ?array
    {
        return $this->where('model_id', $modelId)->orderBy('version_number', 'DESC')->first();
    }
}

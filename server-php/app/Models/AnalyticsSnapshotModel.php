<?php

namespace App\Models;

use CodeIgniter\Model;

class AnalyticsSnapshotModel extends Model
{
    protected $table         = 'reach_analytics_snapshots';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['source', 'captured_at', 'metrics', 'created_at'];
    protected array $casts   = ['metrics' => 'json-array'];
}

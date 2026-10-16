<?php

namespace App\Models;

class AnalyticsSnapshotModel extends ReachModel
{
    protected $table         = 'reach_analytics_snapshots';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['source', 'captured_at', 'metrics', 'created_at'];
    protected array $casts   = ['metrics' => 'json-array'];
}

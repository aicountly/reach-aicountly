<?php

namespace App\Models;

use CodeIgniter\Model;

class WorkerHealthSnapshotModel extends Model
{
    protected $table         = 'reach_worker_health_snapshots';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'checked_at', 'ok', 'http_status', 'latency_ms',
        'response', 'error_message', 'created_at',
    ];

    protected array $casts = ['response' => 'json-array'];
}

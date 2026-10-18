<?php

namespace App\Models;

class WorkerHealthSnapshotModel extends ReachModel
{
    protected $table         = 'reach_worker_health_snapshots';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'checked_at', 'ok', 'http_status', 'latency_ms',
        'response', 'error_message', 'created_at',
    ];

    // PostgreSQL returns booleans as 't'/'f'; without the cast the admin page read 'f' as truthy
    // and showed a failed ping as Ok.
    protected array $casts = ['ok' => 'boolean', 'response' => '?json-array'];
}

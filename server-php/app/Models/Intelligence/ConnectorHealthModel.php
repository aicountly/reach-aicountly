<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class ConnectorHealthModel extends Model
{
    protected $table      = 'reach_connector_health';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'connection_id', 'checked_at', 'status', 'latency_ms',
        'error_message', 'http_status', 'error_class', 'retry_after_at', 'metadata',
    ];

    public function getLatestForConnection(int $connectionId): ?array
    {
        return $this->where('connection_id', $connectionId)->orderBy('checked_at', 'DESC')->first();
    }
}

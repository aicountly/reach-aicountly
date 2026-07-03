<?php

namespace App\Models;

use CodeIgniter\Model;

class ConsoleSyncLogModel extends Model
{
    protected $table         = 'reach_console_sync_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'event_type', 'payload', 'response_status', 'response_body',
        'ok', 'error_message', 'attempted_at',
    ];

    protected array $casts = [
        'payload'       => 'json-array',
        'response_body' => 'json-array',
    ];
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class EngagePushAttemptModel extends Model
{
    protected $table         = 'reach_engage_push_attempts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'lead_id', 'attempt_number', 'request_body', 'response_status',
        'response_body', 'error_message', 'ok', 'attempted_at',
    ];

    protected array $casts = [
        'request_body'  => 'json-array',
        'response_body' => 'json-array',
    ];
}

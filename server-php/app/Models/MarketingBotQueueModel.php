<?php

namespace App\Models;

use CodeIgniter\Model;

class MarketingBotQueueModel extends Model
{
    protected $table         = 'reach_marketing_bot_queue';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'action', 'payload', 'status', 'result_summary',
        'requested_by', 'started_at', 'finished_at', 'error_message',
    ];

    protected array $casts = [
        'payload'        => 'json-array',
        'result_summary' => 'json-array',
    ];
}

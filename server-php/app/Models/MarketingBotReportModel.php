<?php

namespace App\Models;

use CodeIgniter\Model;

class MarketingBotReportModel extends Model
{
    protected $table         = 'reach_marketing_bot_reports';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'queue_id', 'action', 'understanding', 'data_accessed',
        'content_generated', 'recommended_action', 'action_taken',
        'approval_status', 'publishing_status', 'next_recommended_action',
        'mode', 'evidence', 'errors', 'created_by', 'approved_by', 'approved_at',
    ];

    protected array $casts = [
        'data_accessed'     => 'json-array',
        'content_generated' => 'json-array',
        'evidence'          => 'json-array',
        'errors'            => 'json-array',
    ];
}

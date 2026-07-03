<?php

namespace App\Models;

use CodeIgniter\Model;

class CampaignModel extends Model
{
    protected $table         = 'reach_campaigns';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'name', 'campaign_type', 'objective', 'target_audience', 'products_promoted',
        'budget_amount', 'currency', 'start_date', 'end_date', 'status', 'channels',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'landing_page_url', 'creative_copy', 'approval_status', 'approved_by', 'approved_at',
        'analytics_summary', 'leads_generated', 'bot_generated', 'created_by',
    ];

    protected array $casts = [
        'target_audience'   => 'json-array',
        'products_promoted' => 'json-array',
        'channels'          => 'json-array',
        'analytics_summary' => 'json-array',
    ];
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class WhatsappCampaignModel extends Model
{
    protected $table         = 'reach_whatsapp_campaigns';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'campaign_id', 'template_name', 'template_params',
        'audience_filter', 'scheduled_at', 'sent_at', 'status', 'stats', 'created_by',
    ];

    protected array $casts = [
        'template_params' => 'json-array',
        'audience_filter' => 'json-array',
        'stats'           => 'json-array',
    ];
}

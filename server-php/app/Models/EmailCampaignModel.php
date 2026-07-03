<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailCampaignModel extends Model
{
    protected $table         = 'reach_email_campaigns';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'campaign_id', 'subject', 'from_name', 'from_email',
        'body_html', 'body_text', 'audience_filter',
        'scheduled_at', 'sent_at', 'status', 'stats', 'created_by',
    ];

    protected array $casts = [
        'audience_filter' => 'json-array',
        'stats'           => 'json-array',
    ];
}

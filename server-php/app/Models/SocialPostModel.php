<?php

namespace App\Models;

use CodeIgniter\Model;

class SocialPostModel extends Model
{
    protected $table         = 'reach_social_posts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'campaign_id', 'channel', 'content', 'media_refs', 'hashtags',
        'scheduled_at', 'published_at', 'status', 'external_post_id',
        'error_message', 'approval_status', 'approved_by', 'approved_at',
        'bot_generated', 'created_by',
    ];

    protected array $casts = [
        'media_refs' => 'json-array',
        'hashtags'   => 'json-array',
    ];
}

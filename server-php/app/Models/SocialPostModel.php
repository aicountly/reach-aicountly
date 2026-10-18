<?php

namespace App\Models;

class SocialPostModel extends ReachModel
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
        // social-api.ai publishing (2026-10-18-100001)
        'title', 'tenant_id', 'destination_id', 'remote_post_id', 'remote_url', 'provider', 'dispatch_id',
        'platform_account_id', 'provider_status', 'provider_error_code', 'provider_response_json',
        'submitted_at', 'last_polled_at', 'poll_count',
    ];

    protected array $casts = [
        'media_refs' => '?json-array',
        'hashtags'   => '?json-array',
        'provider_response_json' => '?json-array',
    ];
}

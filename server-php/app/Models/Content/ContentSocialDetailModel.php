<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentSocialDetailModel extends Model
{
    protected $table         = 'reach_content_social_details';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'social_platform', 'character_limit', 'hashtags',
        'media_urls', 'thread_position', 'is_thread_root', 'created_by', 'updated_by',
    ];

    protected array $casts = [
        'hashtags'       => 'json-array',
        'media_urls'     => 'json-array',
        'is_thread_root' => 'boolean',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}

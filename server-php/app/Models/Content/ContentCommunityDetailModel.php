<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentCommunityDetailModel extends Model
{
    protected $table         = 'reach_content_community_details';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'community_type', 'answer_for_id', 'forum_category',
        'upvote_count', 'is_accepted_answer', 'created_by', 'updated_by',
    ];

    protected array $casts = [
        'is_accepted_answer' => 'boolean',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}

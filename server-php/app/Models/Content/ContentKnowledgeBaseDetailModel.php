<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentKnowledgeBaseDetailModel extends Model
{
    protected $table         = 'reach_content_knowledge_base_details';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'article_type', 'help_category', 'difficulty_level',
        'related_article_ids', 'applies_to_versions', 'created_by', 'updated_by',
    ];

    protected array $casts = [
        'related_article_ids'  => 'json-array',
        'applies_to_versions'  => 'json-array',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}

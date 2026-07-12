<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentBlogDetailModel extends Model
{
    protected $table         = 'reach_content_blog_details';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'seo_title', 'meta_description', 'canonical_url',
        'estimated_read_minutes', 'has_table_of_contents', 'schema_markup',
        'created_by', 'updated_by',
    ];

    protected array $casts = [
        'schema_markup'           => 'json-array',
        'has_table_of_contents'   => 'boolean',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}

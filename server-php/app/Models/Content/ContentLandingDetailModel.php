<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentLandingDetailModel extends Model
{
    protected $table         = 'reach_content_landing_details';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'page_type', 'hero_headline', 'sub_headline',
        'primary_cta_text', 'primary_cta_url', 'sections', 'seo_title',
        'meta_description', 'conversion_goal', 'created_by', 'updated_by',
    ];

    protected array $casts = [
        'sections' => 'json-array',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}

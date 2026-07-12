<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentEmailDetailModel extends Model
{
    protected $table         = 'reach_content_email_details';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'subject_line', 'preheader', 'from_name', 'reply_to',
        'template_id', 'campaign_type', 'segment_ids', 'personalization_tokens',
        'created_by', 'updated_by',
    ];

    protected array $casts = [
        'segment_ids'             => 'json-array',
        'personalization_tokens'  => 'json-array',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}

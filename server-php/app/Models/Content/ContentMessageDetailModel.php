<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentMessageDetailModel extends Model
{
    protected $table         = 'reach_content_message_details';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'message_type', 'template_name', 'max_characters',
        'has_media', 'media_type', 'buttons', 'created_by', 'updated_by',
    ];

    protected array $casts = [
        'buttons'   => 'json-array',
        'has_media' => 'boolean',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}

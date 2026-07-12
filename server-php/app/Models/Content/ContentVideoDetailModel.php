<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentVideoDetailModel extends Model
{
    protected $table         = 'reach_content_video_details';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'video_type', 'duration_seconds', 'thumbnail_url',
        'video_url', 'speaker_ids', 'chapters', 'transcript_available',
        'created_by', 'updated_by',
    ];

    protected array $casts = [
        'speaker_ids'          => 'json-array',
        'chapters'             => 'json-array',
        'transcript_available' => 'boolean',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}

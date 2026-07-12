<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentPublicationAttemptModel extends Model
{
    protected $table         = 'reach_content_publication_attempts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'publication_target_id', 'content_version_id',
        'status', 'attempted_at', 'blocked_reason', 'metadata',
        'initiated_by', 'cancelled_by', 'cancelled_at',
    ];

    protected array $casts = [
        'metadata' => 'json-array',
    ];

    public function forItem(int $contentItemId): array
    {
        return $this->where('content_item_id', $contentItemId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }
}

<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentVersionModel extends Model
{
    protected $table         = 'reach_content_versions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'content_item_id', 'version_number', 'title', 'summary',
        'body_html', 'body_markdown', 'body_plain_text', 'structured_payload',
        'change_summary', 'is_current',
        'created_actor_type', 'created_by_user_id', 'created_by_service',
        'source_generation_id', 'request_id', 'created_at',
    ];

    protected array $casts = [
        'structured_payload' => '?json-array',
        'is_current'         => 'boolean',
    ];

    public function currentForItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)
            ->where('is_current', true)
            ->first();
    }

    public function allForItem(int $contentItemId): array
    {
        return $this->where('content_item_id', $contentItemId)
            ->orderBy('version_number', 'DESC')
            ->findAll();
    }

    public function nextVersionNumber(int $contentItemId): int
    {
        $max = $this->selectMax('version_number', 'max_ver')
            ->where('content_item_id', $contentItemId)
            ->first();
        return ($max['max_ver'] ?? 0) + 1;
    }
}

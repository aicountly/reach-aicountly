<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentCommentModel extends Model
{
    protected $table         = 'reach_content_comments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $allowedFields = [
        'content_item_id', 'version_id', 'parent_comment_id', 'body',
        'internal_only', 'resolved_at', 'resolved_by',
        'created_by', 'created_actor_type',
    ];

    protected array $casts = [
        'internal_only' => 'boolean',
    ];

    public function threadForItem(int $contentItemId, bool $includeResolved = false): array
    {
        $q = $this->where('content_item_id', $contentItemId)
            ->where('parent_comment_id IS NULL')
            ->withDeleted(false);

        if (!$includeResolved) {
            $q = $q->where('resolved_at IS NULL');
        }

        return $q->orderBy('created_at', 'ASC')->findAll();
    }

    public function repliesFor(int $parentCommentId): array
    {
        return $this->where('parent_comment_id', $parentCommentId)
            ->withDeleted(false)
            ->orderBy('created_at', 'ASC')
            ->findAll();
    }
}

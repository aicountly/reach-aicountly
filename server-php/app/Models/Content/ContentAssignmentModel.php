<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentAssignmentModel extends Model
{
    protected $table         = 'reach_content_assignments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'user_id', 'role', 'assigned_at', 'unassigned_at',
        'due_date', 'notes', 'assigned_by',
    ];

    public function forItem(int $contentItemId): array
    {
        return $this->where('content_item_id', $contentItemId)
            ->where('unassigned_at IS NULL')
            ->findAll();
    }

    public function forUser(int $userId, ?string $role = null): array
    {
        $q = $this->where('user_id', $userId)->where('unassigned_at IS NULL');
        if ($role !== null) {
            $q = $q->where('role', $role);
        }
        return $q->findAll();
    }

    public function activeAssignment(int $contentItemId, int $userId, string $role): ?array
    {
        return $this->where('content_item_id', $contentItemId)
            ->where('user_id', $userId)
            ->where('role', $role)
            ->where('unassigned_at IS NULL')
            ->first();
    }
}

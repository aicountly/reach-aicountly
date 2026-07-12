<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationModel extends Model
{
    protected $table         = 'reach_notifications';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'uuid', 'recipient_id', 'notification_type', 'entity_type', 'entity_id',
        'message', 'action_url', 'data', 'read_at', 'dismissed_at',
        'created_by', 'created_at',
    ];

    protected array $casts = [
        'data' => 'json-array',
    ];

    public function unreadForUser(int $userId): array
    {
        return $this->where('recipient_id', $userId)
            ->where('read_at IS NULL')
            ->where('dismissed_at IS NULL')
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    public function markRead(int $notificationId): void
    {
        $this->where('id', $notificationId)->set(['read_at' => date('Y-m-d H:i:s')])->update();
    }

    public function markAllReadForUser(int $userId): void
    {
        $this->where('recipient_id', $userId)
            ->where('read_at IS NULL')
            ->set(['read_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    public function unreadCountForUser(int $userId): int
    {
        return $this->where('recipient_id', $userId)
            ->where('read_at IS NULL')
            ->where('dismissed_at IS NULL')
            ->countAllResults();
    }
}

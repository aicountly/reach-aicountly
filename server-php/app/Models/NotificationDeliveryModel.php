<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationDeliveryModel extends Model
{
    protected $table         = 'reach_notification_deliveries';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'notification_id', 'channel', 'status', 'sent_at', 'failed_at', 'failure_reason',
    ];

    public function forNotification(int $notificationId): array
    {
        return $this->where('notification_id', $notificationId)->findAll();
    }
}

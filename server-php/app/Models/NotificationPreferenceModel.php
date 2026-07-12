<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationPreferenceModel extends Model
{
    protected $table         = 'reach_notification_preferences';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'user_id', 'notification_type', 'in_app_enabled', 'email_enabled', 'digest_only',
    ];

    protected array $casts = [
        'in_app_enabled' => 'boolean',
        'email_enabled'  => 'boolean',
        'digest_only'    => 'boolean',
    ];

    public function forUser(int $userId): array
    {
        return $this->where('user_id', $userId)->findAll();
    }

    public function getPreference(int $userId, string $type): ?array
    {
        return $this->where('user_id', $userId)->where('notification_type', $type)->first();
    }
}

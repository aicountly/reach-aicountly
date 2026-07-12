<?php

namespace App\Models;

use CodeIgniter\Model;

class UserPermissionModel extends Model
{
    protected $table         = 'reach_user_permissions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'user_id', 'permission', 'mode', 'reason', 'created_by',
    ];

    /**
     * @return array{grants: string[], denies: string[]}
     */
    public function overridesFor(int $userId): array
    {
        $rows = $this->where('user_id', $userId)->findAll();
        $grants = [];
        $denies = [];
        foreach ($rows as $row) {
            if (($row['mode'] ?? 'grant') === 'deny') {
                $denies[] = (string) $row['permission'];
            } else {
                $grants[] = (string) $row['permission'];
            }
        }
        return ['grants' => $grants, 'denies' => $denies];
    }
}

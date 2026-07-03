<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table         = 'reach_users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'email', 'name', 'password_hash', 'role_id', 'is_active',
        'last_login_at', 'last_login_ip', 'failed_attempts',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', strtolower($email))->first();
    }

    public function withRole(int $id): ?array
    {
        $row = $this->db->table($this->table . ' u')
            ->select('u.*, r.slug as role_slug, r.name as role_name')
            ->join('reach_roles r', 'r.id = u.role_id', 'left')
            ->where('u.id', $id)
            ->get()->getRowArray();
        return $row ?: null;
    }
}

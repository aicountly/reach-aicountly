<?php

namespace App\Models;

class RoleModel extends ReachModel
{
    protected $table         = 'reach_roles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $allowedFields = ['slug', 'name', 'description', 'permissions'];
    protected array $casts   = ['permissions' => '?json-array'];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }
}

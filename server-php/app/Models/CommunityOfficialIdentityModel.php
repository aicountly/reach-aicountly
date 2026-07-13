<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityOfficialIdentityModel extends Model
{
    protected $table         = 'reach_community_official_identities';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'slug', 'display_name', 'department', 'badge_type',
        'avatar_reference', 'authorised_scopes', 'disclosure_template',
        'approval_requirements', 'is_active',
    ];

    protected array $casts = [
        'authorised_scopes'    => 'json-array',
        'approval_requirements' => 'json-array',
        'is_active'            => 'boolean',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function listActive(): array
    {
        return $this->where('is_active', true)->orderBy('display_name')->findAll();
    }
}

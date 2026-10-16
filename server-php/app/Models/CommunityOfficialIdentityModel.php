<?php

namespace App\Models;

class CommunityOfficialIdentityModel extends ReachModel
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
        'public_slug', 'short_description', 'topic_specialisation',
        'operational_role', 'ai_disclosure', 'public_external_id',
        'created_by', 'approved_by',
    ];

    protected array $casts = [
        'authorised_scopes'    => 'pg-text-array', // a text[] column, not JSON
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

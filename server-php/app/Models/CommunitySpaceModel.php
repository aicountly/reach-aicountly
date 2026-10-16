<?php

namespace App\Models;

class CommunitySpaceModel extends ReachModel
{
    protected $table         = 'reach_community_spaces';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'slug', 'title', 'description', 'visibility',
        'moderation_mode', 'official_answer_policy', 'allowed_content_types',
        'indexing_policy', 'status',
    ];

    protected array $casts = ['allowed_content_types' => 'pg-text-array']; // a text[] column, not JSON

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function listActive(): array
    {
        return $this->where('status', 'active')->findAll();
    }
}

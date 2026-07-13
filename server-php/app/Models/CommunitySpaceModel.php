<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunitySpaceModel extends Model
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

    protected array $casts = ['allowed_content_types' => 'json-array'];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function listActive(): array
    {
        return $this->where('status', 'active')->findAll();
    }
}

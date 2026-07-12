<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentPublicationTargetModel extends Model
{
    protected $table         = 'reach_content_publication_targets';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $allowedFields = [
        'uuid', 'name', 'channel', 'target_url', 'target_config',
        'is_active', 'notes', 'created_by', 'updated_by',
    ];

    protected array $casts = [
        'target_config' => 'json-array',
        'is_active'     => 'boolean',
    ];

    public function activeTargets(): array
    {
        return $this->where('is_active', true)->withDeleted(false)->findAll();
    }

    public function findByChannel(string $channel): array
    {
        return $this->where('channel', $channel)->where('is_active', true)->withDeleted(false)->findAll();
    }
}

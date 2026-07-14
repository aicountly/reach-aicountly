<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoPublicationProfileModel extends Model
{
    protected $table         = 'reach_video_publication_profiles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'project_id', 'tenant_id', 'platform', 'title', 'description',
        'tags', 'category_id', 'privacy_status', 'thumbnail_asset_id',
        'extra_metadata', 'created_by',
    ];

    protected array $casts = [
        'tags'           => '?json-array',
        'extra_metadata' => '?json-array',
    ];

    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }
        return $this->where('uuid', $uuid)->first();
    }

    public function findByProject(int $projectId, string $platform = 'youtube'): ?array
    {
        return $this->where('project_id', $projectId)
            ->where('platform', $platform)
            ->first();
    }
}

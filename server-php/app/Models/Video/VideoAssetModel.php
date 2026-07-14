<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoAssetModel extends Model
{
    protected $table         = 'reach_video_assets';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'project_id', 'tenant_id', 'asset_type', 'mime_type',
        'file_extension', 'storage_key', 'file_size_bytes', 'checksum_sha256',
        'status', 'rejection_reason', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }
        return $this->where('uuid', $uuid)->first();
    }

    public function listForProject(int $projectId, string $assetType = ''): array
    {
        $builder = $this->where('project_id', $projectId)
            ->where('status !=', 'deleted');
        if ($assetType !== '') {
            $builder->where('asset_type', $assetType);
        }
        return $builder->orderBy('created_at', 'DESC')->findAll();
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoRenderProfileModel extends Model
{
    protected $table         = 'reach_video_render_profiles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'name', 'description', 'resolution',
        'frame_rate', 'bitrate_kbps', 'output_format', 'extra_config',
        'is_default', 'is_active', 'created_by',
    ];

    protected array $casts = [
        'extra_config' => '?json-array',
        'is_default'   => 'boolean',
        'is_active'    => 'boolean',
    ];

    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }
        return $this->where('uuid', $uuid)->first();
    }

    public function getDefaultForTenant(int $tenantId): ?array
    {
        return $this->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    public function listForTenant(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('is_default', 'DESC')
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}

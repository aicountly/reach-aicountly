<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoScriptVersionModel extends Model
{
    protected $table         = 'reach_video_script_versions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'uuid', 'script_id', 'version_number', 'content_json',
        'generation_artifact_id', 'validation_run_id',
        'approved_by', 'approved_at', 'submitted_by', 'created_by', 'is_current',
    ];

    protected array $casts = [
        'content_json' => 'json-array',
        'is_current'   => 'boolean',
    ];

    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }
        return $this->where('uuid', $uuid)->first();
    }

    public function getCurrentVersion(int $scriptId): ?array
    {
        return $this->where('script_id', $scriptId)
            ->where('is_current', true)
            ->first();
    }

    public function listVersions(int $scriptId): array
    {
        return $this->where('script_id', $scriptId)
            ->orderBy('version_number', 'DESC')
            ->findAll();
    }

    public function getByVersionNumber(int $scriptId, int $versionNumber): ?array
    {
        return $this->where('script_id', $scriptId)
            ->where('version_number', $versionNumber)
            ->first();
    }

    public function clearCurrentFlag(int $scriptId): void
    {
        $this->db->table($this->table)
            ->where('script_id', $scriptId)
            ->update(['is_current' => false]);
    }
}

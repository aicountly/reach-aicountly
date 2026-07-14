<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoRenderJobModel extends Model
{
    protected $table         = 'reach_video_render_jobs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'project_id', 'script_version_id', 'render_profile_id', 'provider',
        'idempotency_key', 'status', 'attempt_count', 'max_attempts',
        'output_asset_id', 'failure_class', 'failure_message', 'provider_job_id',
        'reserved_at', 'completed_at', 'available_at', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }
        return $this->where('uuid', $uuid)->first();
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }

    public function findByProviderJobId(string $providerJobId): ?array
    {
        return $this->where('provider_job_id', $providerJobId)->first();
    }

    public function listForProject(int $projectId): array
    {
        return $this->where('project_id', $projectId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    public function reserveNext(): ?array
    {
        $now = date('Y-m-d H:i:s');
        $row = $this->db->table($this->table)
            ->where('status', 'queued')
            ->where('available_at <=', $now)
            ->orderBy('available_at', 'ASC')
            ->limit(1)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        $updated = $this->db->table($this->table)
            ->where('id', $row['id'])
            ->where('status', 'queued')
            ->update([
                'status'      => 'reserved',
                'reserved_at' => $now,
                'updated_at'  => $now,
            ]);

        return $updated ? $this->find($row['id']) : null;
    }
}

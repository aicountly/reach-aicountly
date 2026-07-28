<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoProjectModel extends Model
{
    protected $table         = 'reach_video_projects';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'idea_id', 'title', 'status',
        'approved_script_version_id', 'lock_version', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }
        return $this->where('uuid', $uuid)->first();
    }

    public function listForTenant(int $tenantId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        // Avoid table aliases in Query Builder — some CI4/Postgres
        // identifier escaping paths quote "table alias" as one name.
        $builder = $this->db->table($this->table)
            ->select('reach_video_projects.*, reach_video_ideas.title AS idea_title')
            ->join(
                'reach_video_ideas',
                'reach_video_ideas.id = reach_video_projects.idea_id',
                'left'
            )
            ->where('reach_video_projects.tenant_id', $tenantId);

        if (! empty($filters['status'])) {
            $builder->where('reach_video_projects.status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $builder->like('reach_video_projects.title', $filters['search']);
        }

        $total  = $builder->countAllResults(false);
        $offset = ($page - 1) * $perPage;
        $rows   = $builder->orderBy('reach_video_projects.updated_at', 'DESC')
            ->limit($perPage, $offset)
            ->get()->getResultArray();

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function transitionStatus(int $id, string $newStatus, int $lockVersion): bool
    {
        $affected = $this->db->table($this->table)
            ->where('id', $id)
            ->where('lock_version', $lockVersion)
            ->update([
                'status'       => $newStatus,
                'lock_version' => $lockVersion + 1,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        return (bool) $affected;
    }
}

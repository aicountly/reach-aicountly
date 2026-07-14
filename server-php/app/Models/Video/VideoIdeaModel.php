<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoIdeaModel extends Model
{
    protected $table         = 'reach_video_ideas';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'title', 'summary', 'status',
        'score_total', 'score_breakdown', 'rationale',
        'source_type', 'source_ref_id', 'generation_request_id',
        'similarity_score', 'duplicate_of_id', 'created_by',
    ];

    protected array $casts = [
        'score_breakdown' => '?json-array',
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
        $builder = $this->db->table($this->table)
            ->where('tenant_id', $tenantId);

        if (! empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }
        if (isset($filters['min_score'])) {
            $builder->where('score_total >=', (int) $filters['min_score']);
        }
        if (! empty($filters['search'])) {
            $builder->groupStart()
                ->like('title', $filters['search'])
                ->orLike('summary', $filters['search'])
                ->groupEnd();
        }

        $total  = $builder->countAllResults(false);
        $offset = ($page - 1) * $perPage;
        $rows   = $builder->orderBy('score_total', 'DESC NULLS LAST')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage, $offset)
            ->get()->getResultArray();

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}

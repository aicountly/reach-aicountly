<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class SourceModel extends Model
{
    protected $table          = 'reach_sources';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'slug', 'name', 'url', 'source_type', 'authority_score',
        'description', 'is_active', 'valid_from', 'valid_until',
        'status', 'internal_notes',
        'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'created_actor_type', 'request_id',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function findApproved(): array
    {
        return $this->where('status', 'approved')
            ->where('is_active', true)
            ->orderBy('authority_score', 'DESC')
            ->findAll();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $builder = $this->where('slug', $slug);
        if ($excludeId !== null) {
            $builder = $builder->where('id !=', $excludeId);
        }
        return $builder->countAllResults() > 0;
    }

    public function listPaged(int $page, int $limit, array $filters = []): array
    {
        $builder = $this;
        if (! empty($filters['status'])) {
            $builder = $builder->where('status', $filters['status']);
        }
        if (! empty($filters['source_type'])) {
            $builder = $builder->where('source_type', $filters['source_type']);
        }
        if (! empty($filters['q'])) {
            $builder = $builder->groupStart()
                ->like('name', $filters['q'])
                ->orLike('url', $filters['q'])
                ->groupEnd();
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('name', 'ASC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

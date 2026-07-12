<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table         = 'reach_products';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $allowedFields = [
        'slug', 'name', 'short_description', 'description', 'public_url',
        'status', 'legacy_code_path', 'internal_notes',
        'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'review_due_at', 'next_review_at',
        'created_actor_type', 'created_by_service', 'generation_job_id', 'request_id',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    /** Return approved products only (for grounding). */
    public function findApproved(): array
    {
        return $this->where('status', 'approved')->findAll();
    }

    /** Return approved product by slug (for grounding). */
    public function findApprovedBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->where('status', 'approved')->first();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $builder = $this->where('slug', $slug);
        if ($excludeId !== null) {
            $builder = $builder->where('id !=', $excludeId);
        }
        return $builder->countAllResults() > 0;
    }

    /** List products with pagination, filtering, and optional status. */
    public function listPaged(int $page, int $limit, array $filters = []): array
    {
        $builder = $this;
        if (! empty($filters['status'])) {
            $builder = $builder->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $builder = $builder->groupStart()
                ->like('name', $filters['q'])
                ->orLike('slug', $filters['q'])
                ->groupEnd();
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('name', 'ASC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

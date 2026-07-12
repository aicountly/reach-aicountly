<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class ProductModuleModel extends Model
{
    protected $table          = 'reach_product_modules';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'product_id', 'slug', 'name', 'description', 'status', 'sort_order',
        'internal_notes', 'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'created_actor_type', 'created_by_service',
        'generation_job_id', 'request_id',
    ];

    public function forProduct(int $productId, bool $approvedOnly = false): array
    {
        $builder = $this->where('product_id', $productId);
        if ($approvedOnly) {
            $builder = $builder->where('status', 'approved');
        }
        return $builder->orderBy('sort_order', 'ASC')->findAll();
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $builder = $this->where('slug', $slug);
        if ($excludeId !== null) {
            $builder = $builder->where('id !=', $excludeId);
        }
        return $builder->countAllResults() > 0;
    }
}

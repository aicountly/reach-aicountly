<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class ProductFeatureModel extends Model
{
    protected $table          = 'reach_product_features';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'module_id', 'slug', 'name', 'description', 'availability', 'availability_notes',
        'status', 'sort_order', 'internal_notes',
        'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'created_actor_type', 'created_by_service',
        'generation_job_id', 'request_id',
    ];

    public function forModule(int $moduleId, bool $approvedOnly = false): array
    {
        $builder = $this->where('module_id', $moduleId);
        if ($approvedOnly) {
            $builder = $builder->where('status', 'approved');
        }
        return $builder->orderBy('sort_order', 'ASC')->findAll();
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function forProductApproved(int $productId): array
    {
        return $this->db->table('reach_product_features f')
            ->select('f.*')
            ->join('reach_product_modules m', 'm.id = f.module_id')
            ->where('m.product_id', $productId)
            ->where('f.status', 'approved')
            ->where('f.deleted_at IS NULL')
            ->orderBy('f.sort_order', 'ASC')
            ->get()->getResultArray();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $builder = $this->where('slug', $slug);
        if ($excludeId !== null) {
            $builder = $builder->where('id !=', $excludeId);
        }
        return $builder->countAllResults() > 0;
    }

    /** Count features for a product, grouped by availability. */
    public function availabilityCountsForProduct(int $productId): array
    {
        $rows = $this->db->table('reach_product_features f')
            ->select('f.availability, COUNT(*) as cnt')
            ->join('reach_product_modules m', 'm.id = f.module_id')
            ->where('m.product_id', $productId)
            ->where('f.deleted_at IS NULL')
            ->groupBy('f.availability')
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['availability']] = (int) $row['cnt'];
        }
        return $out;
    }
}

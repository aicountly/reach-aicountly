<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class BrandRuleModel extends Model
{
    protected $table          = 'reach_brand_rules';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'product_id', 'rule_type', 'rule_text', 'applies_to', 'is_mandatory',
        'status', 'internal_notes',
        'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'created_actor_type', 'request_id',
    ];

    public function forProduct(?int $productId, bool $approvedOnly = false): array
    {
        $builder = $productId !== null
            ? $this->groupStart()->where('product_id', $productId)->orWhere('product_id IS NULL')->groupEnd()
            : $this->where('product_id IS NULL');
        if ($approvedOnly) {
            $builder = $builder->where('status', 'approved');
        }
        return $builder->orderBy('rule_type', 'ASC')->findAll();
    }

    public function globalApproved(): array
    {
        return $this->where('product_id IS NULL')
            ->where('status', 'approved')
            ->orderBy('rule_type', 'ASC')
            ->findAll();
    }

    public function listPaged(int $page, int $limit, array $filters = []): array
    {
        $builder = $this;
        if (! empty($filters['product_id'])) {
            $builder = $builder->where('product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['status'])) {
            $builder = $builder->where('status', $filters['status']);
        }
        if (! empty($filters['rule_type'])) {
            $builder = $builder->where('rule_type', $filters['rule_type']);
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('rule_type', 'ASC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

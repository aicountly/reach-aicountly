<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class MarketModel extends Model
{
    protected $table          = 'reach_markets';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'slug', 'name', 'region', 'country_codes', 'jurisdiction_notes', 'description',
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
        return $this->where('status', 'approved')->orderBy('name', 'ASC')->findAll();
    }

    public function forProduct(int $productId, bool $approvedOnly = true): array
    {
        $builder = $this->db->table('reach_markets m')
            ->select('m.*')
            ->join('reach_product_markets rpm', 'rpm.market_id = m.id')
            ->where('rpm.product_id', $productId)
            ->where('m.deleted_at IS NULL');
        if ($approvedOnly) {
            $builder->where('m.status', 'approved');
        }
        return $builder->orderBy('m.name', 'ASC')->get()->getResultArray();
    }

    public function listPaged(int $page, int $limit, array $filters = []): array
    {
        $builder = $this;
        if (! empty($filters['status'])) {
            $builder = $builder->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $builder = $builder->like('name', $filters['q']);
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('name', 'ASC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

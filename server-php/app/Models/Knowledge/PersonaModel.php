<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class PersonaModel extends Model
{
    protected $table          = 'reach_personas';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'slug', 'name', 'role_title', 'description', 'pain_points', 'goals',
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
        $builder = $this->db->table('reach_personas p')
            ->select('p.*')
            ->join('reach_product_personas rpp', 'rpp.persona_id = p.id')
            ->where('rpp.product_id', $productId)
            ->where('p.deleted_at IS NULL');
        if ($approvedOnly) {
            $builder->where('p.status', 'approved');
        }
        return $builder->orderBy('p.name', 'ASC')->get()->getResultArray();
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

<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class SearchIntentModel extends Model
{
    protected $table          = 'reach_search_intents';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'slug', 'intent_text', 'intent_type', 'funnel_stage',
        'search_volume', 'difficulty_score', 'notes',
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
        return $this->where('status', 'approved')->orderBy('intent_text', 'ASC')->findAll();
    }

    public function forProduct(int $productId, bool $approvedOnly = true): array
    {
        $builder = $this->db->table('reach_search_intents si')
            ->select('si.*')
            ->join('reach_intent_products rip', 'rip.intent_id = si.id')
            ->where('rip.product_id', $productId)
            ->where('si.deleted_at IS NULL');
        if ($approvedOnly) {
            $builder->where('si.status', 'approved');
        }
        return $builder->orderBy('si.intent_text', 'ASC')->get()->getResultArray();
    }

    public function listPaged(int $page, int $limit, array $filters = []): array
    {
        $builder = $this;
        if (! empty($filters['status'])) {
            $builder = $builder->where('status', $filters['status']);
        }
        if (! empty($filters['intent_type'])) {
            $builder = $builder->where('intent_type', $filters['intent_type']);
        }
        if (! empty($filters['funnel_stage'])) {
            $builder = $builder->where('funnel_stage', $filters['funnel_stage']);
        }
        if (! empty($filters['q'])) {
            $builder = $builder->like('intent_text', $filters['q']);
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('intent_text', 'ASC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

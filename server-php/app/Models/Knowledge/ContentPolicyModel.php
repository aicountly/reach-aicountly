<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class ContentPolicyModel extends Model
{
    protected $table          = 'reach_content_policies';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'name', 'policy_type', 'policy_text', 'applies_to_channels', 'is_mandatory',
        'status', 'internal_notes',
        'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'created_actor_type', 'request_id',
    ];

    public function findApproved(): array
    {
        return $this->where('status', 'approved')->orderBy('policy_type', 'ASC')->findAll();
    }

    public function findApprovedForChannel(string $channel): array
    {
        return $this->db->table($this->table)
            ->where('status', 'approved')
            ->where('deleted_at IS NULL')
            ->where("(applies_to_channels IS NULL OR applies_to_channels @> '\"" . $this->db->escapeString($channel) . "\"')")
            ->orderBy('policy_type', 'ASC')
            ->get()->getResultArray();
    }

    public function listPaged(int $page, int $limit, array $filters = []): array
    {
        $builder = $this;
        if (! empty($filters['status'])) {
            $builder = $builder->where('status', $filters['status']);
        }
        if (! empty($filters['policy_type'])) {
            $builder = $builder->where('policy_type', $filters['policy_type']);
        }
        if (! empty($filters['q'])) {
            $builder = $builder->like('name', $filters['q']);
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('policy_type', 'ASC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

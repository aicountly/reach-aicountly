<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class ProductClaimModel extends Model
{
    protected $table          = 'reach_product_claims';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'product_id', 'claim_text', 'claim_summary', 'risk_level',
        'requires_evidence', 'valid_from', 'valid_until',
        'status', 'internal_notes',
        'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'created_actor_type', 'created_by_service',
        'generation_job_id', 'request_id',
    ];

    public function forProduct(int $productId, bool $approvedOnly = false): array
    {
        $builder = $this->where('product_id', $productId);
        if ($approvedOnly) {
            $builder = $builder->where('status', 'approved');
        }
        return $builder->orderBy('risk_level', 'DESC')->findAll();
    }

    public function forProductApproved(int $productId): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->where('product_id', $productId)
            ->where('status', 'approved')
            ->groupStart()
                ->where('valid_until IS NULL')
                ->orWhere('valid_until >=', $now)
            ->groupEnd()
            ->orderBy('risk_level', 'DESC')
            ->findAll();
    }

    /** Count approved evidence items linked to a specific claim. */
    public function approvedEvidenceCount(int $claimId): int
    {
        return (int) $this->db->table('reach_claim_evidence ce')
            ->join('reach_evidence e', 'e.id = ce.evidence_id')
            ->where('ce.claim_id', $claimId)
            ->where('e.status', 'approved')
            ->where('e.deleted_at IS NULL')
            ->countAllResults();
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
        if (! empty($filters['risk_level'])) {
            $builder = $builder->where('risk_level', $filters['risk_level']);
        }
        if (! empty($filters['q'])) {
            $builder = $builder->like('claim_summary', $filters['q']);
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('risk_level', 'DESC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

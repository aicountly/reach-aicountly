<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class EvidenceModel extends Model
{
    protected $table          = 'reach_evidence';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'slug', 'title', 'summary', 'evidence_type', 'source_id', 'external_url',
        'valid_from', 'valid_until', 'status', 'internal_notes',
        'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'created_actor_type', 'created_by_service',
        'generation_job_id', 'request_id',
    ];

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function findApproved(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->where('status', 'approved')
            ->groupStart()
                ->where('valid_until IS NULL')
                ->orWhere('valid_until >=', $now)
            ->groupEnd()
            ->findAll();
    }

    /** Count approved, non-expired evidence for a product claim. */
    public function approvedCountForClaim(int $claimId): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) $this->db->table('reach_evidence e')
            ->join('reach_claim_evidence ce', 'ce.evidence_id = e.id')
            ->where('ce.claim_id', $claimId)
            ->where('e.status', 'approved')
            ->where('e.deleted_at IS NULL')
            ->groupStart()
                ->where('e.valid_until IS NULL')
                ->orWhere('e.valid_until >=', $now)
            ->groupEnd()
            ->countAllResults();
    }

    public function forClaim(int $claimId, bool $approvedOnly = true): array
    {
        $builder = $this->db->table('reach_evidence e')
            ->select('e.*')
            ->join('reach_claim_evidence ce', 'ce.evidence_id = e.id')
            ->where('ce.claim_id', $claimId)
            ->where('e.deleted_at IS NULL');
        if ($approvedOnly) {
            $builder->where('e.status', 'approved');
        }
        return $builder->get()->getResultArray();
    }

    public function isExpired(array $evidence): bool
    {
        if (empty($evidence['valid_until'])) {
            return false;
        }
        return strtotime($evidence['valid_until']) < time();
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
        if (! empty($filters['evidence_type'])) {
            $builder = $builder->where('evidence_type', $filters['evidence_type']);
        }
        if (! empty($filters['q'])) {
            $builder = $builder->like('title', $filters['q']);
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('created_at', 'DESC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

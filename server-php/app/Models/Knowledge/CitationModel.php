<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class CitationModel extends Model
{
    protected $table          = 'reach_citations';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'source_id', 'evidence_id', 'citation_text', 'page_reference',
        'accessed_at', 'status',
        'created_by', 'updated_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'request_id',
    ];

    public function forSource(int $sourceId, bool $approvedOnly = false): array
    {
        $builder = $this->where('source_id', $sourceId);
        if ($approvedOnly) {
            $builder = $builder->where('status', 'approved');
        }
        return $builder->findAll();
    }

    public function forEvidence(int $evidenceId): array
    {
        return $this->where('evidence_id', $evidenceId)->findAll();
    }

    public function listPaged(int $page, int $limit, array $filters = []): array
    {
        $builder = $this;
        if (! empty($filters['source_id'])) {
            $builder = $builder->where('source_id', (int) $filters['source_id']);
        }
        if (! empty($filters['status'])) {
            $builder = $builder->where('status', $filters['status']);
        }
        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('created_at', 'DESC')->findAll($limit, ($page - 1) * $limit);
        return ['items' => $items, 'total' => $total];
    }
}

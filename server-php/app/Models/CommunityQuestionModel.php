<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityQuestionModel extends Model
{
    protected $table         = 'reach_community_questions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'content_item_id', 'space_id', 'source_type', 'source_url',
        'external_question_id', 'author_reference', 'author_display_consent',
        'title', 'body', 'language', 'product', 'category', 'tags',
        'jurisdiction', 'question_timestamp', 'intake_timestamp',
        'sensitivity_flags', 'personal_data_detected', 'spam_score',
        'moderation_state', 'duplicate_cluster_id', 'triage_score',
        'assigned_to', 'status',
    ];

    protected array $casts = [
        'tags'               => 'json-array',
        'sensitivity_flags'  => 'json-array',
        'author_display_consent' => 'boolean',
        'personal_data_detected' => 'boolean',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function listForInbox(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $builder = $this->db->table($this->table . ' q')
            ->select('q.*, s.title AS space_title, s.slug AS space_slug')
            ->join('reach_community_spaces s', 's.id = q.space_id', 'left');

        if (!empty($filters['status'])) {
            $builder->where('q.status', $filters['status']);
        }
        if (!empty($filters['space_id'])) {
            $builder->where('q.space_id', (int) $filters['space_id']);
        }
        if (!empty($filters['source_type'])) {
            $builder->where('q.source_type', $filters['source_type']);
        }
        if (!empty($filters['assigned_to'])) {
            $builder->where('q.assigned_to', (int) $filters['assigned_to']);
        }
        if (!empty($filters['language'])) {
            $builder->where('q.language', $filters['language']);
        }
        if (!empty($filters['search'])) {
            $builder->groupStart()
                ->like('q.title', $filters['search'])
                ->orLike('q.body', $filters['search'])
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $offset = ($page - 1) * $perPage;

        $rows = $builder->orderBy('q.triage_score', 'DESC')
            ->orderBy('q.intake_timestamp', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function countByStatus(): array
    {
        $rows = $this->db->table($this->table)
            ->select('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['count'];
        }
        return $result;
    }
}

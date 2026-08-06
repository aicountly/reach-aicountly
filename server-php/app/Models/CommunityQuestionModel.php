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
        'public_question_id', 'public_question_slug', 'public_url', 'published_at',
    ];

    // tags / sensitivity_flags are native Postgres TEXT[] columns. The old
    // 'json-array' casts serialised PHP arrays to JSON ("[]"), which Postgres
    // rejects as a malformed array literal on write and which json_decode
    // chokes on when reading a real PG literal ('{a,b}'). Writes go through
    // CommunityQuestionRepository::save(), which encodes PG literals.
    protected array $casts = [
        'author_display_consent' => 'boolean',
        'personal_data_detected' => 'boolean',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    /** Column expressions the inbox sorts by, keyed by the API's sort tokens. */
    private const SORTS = [
        'triage_score_desc' => ['q.triage_score', 'DESC'],
        'triage_score_asc'  => ['q.triage_score', 'ASC'],
        'newest'            => ['q.intake_timestamp', 'DESC'],
        'oldest'            => ['q.intake_timestamp', 'ASC'],
    ];

    public function listForInbox(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        // The official answer carries the risk tier and the live public URL;
        // the inbox shows both, so they are joined here rather than fetched
        // per row by the client.
        $builder = $this->db->table($this->table . ' q')
            ->select(
                'q.*, s.title AS space_title, s.slug AS space_slug, '
                . 'a.uuid AS answer_uuid, a.status AS answer_status, '
                . 'a.risk_classification, a.risk_tier, '
                . 'a.public_url AS answer_public_url'
            )
            ->join('reach_community_spaces s', 's.id = q.space_id', 'left')
            ->join('reach_community_official_answers a', 'a.question_id = q.id', 'left');

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

        [$sortColumn, $sortDir] = self::SORTS[$filters['sort'] ?? ''] ?? self::SORTS['triage_score_desc'];

        $rows = $builder->orderBy($sortColumn, $sortDir)
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

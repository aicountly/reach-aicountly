<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class CommunityQuestionModel extends Model
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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

    // tags / sensitivity_flags are native Postgres TEXT[] columns. The old
    // 'json-array' casts serialised PHP arrays to JSON ("[]"), which Postgres
    // rejects as a malformed array literal on write and which json_decode
    // chokes on when reading a real PG literal ('{a,b}'). Writes go through
    // CommunityQuestionRepository::save(), which encodes PG literals.
    protected array $casts = [
        'author_display_consent' => 'boolean',
        'personal_data_detected' => 'boolean',
    ];

    // A malformed id must be a miss, not a query: Postgres rejects it on the
    // uuid column, which surfaced as a 500 for /community/questions/undefined.
    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match(self::UUID_PATTERN, $uuid)) {
            return null;
        }
        return $this->where('uuid', $uuid)->first();
    }

    /** One question in the same shape as an inbox row. */
    public function findDetailByUuid(string $uuid): ?array
    {
        if (! preg_match(self::UUID_PATTERN, $uuid)) {
            return null;
        }
        $row = $this->inboxQuery()->where('q.uuid', $uuid)->get()->getRowArray();

        return $row === null ? null : $this->convertToReturnType($row, 'array');
    }

    public function listForInbox(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $builder = $this->inboxQuery();

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

        // A raw builder skips $casts, so Postgres booleans would arrive as the
        // strings 't'/'f' — and 'f' is truthy to every client.
        $rows = array_map(fn (array $row): array => $this->convertToReturnType($row, 'array'), $rows);

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * The question with its space and current risk.
     *
     * Risk is not a question column: it lives on the classification rows. A
     * question can carry several (older duplicates survive), and the
     * classifier keeps the highest id current, so that is the row read here.
     * It is a SELECT-list subquery rather than a LATERAL join so that
     * countAllResults(), which swaps the SELECT list out, skips it.
     */
    private function inboxQuery(): BaseBuilder
    {
        return $this->db->table($this->table . ' q')
            ->select('q.*, s.title AS space_title, s.slug AS space_slug')
            ->select(
                '(SELECT c.risk_classification FROM reach_community_question_classifications c'
                . ' WHERE c.question_id = q.id ORDER BY c.id DESC LIMIT 1) AS risk_classification',
                false
            )
            ->join('reach_community_spaces s', 's.id = q.space_id', 'left');
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

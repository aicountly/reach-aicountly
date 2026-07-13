<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityOfficialAnswerModel extends Model
{
    protected $table         = 'reach_community_official_answers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'question_id', 'identity_id', 'current_version',
        'approved_version', 'approved_version_checksum',
        'public_external_id', 'public_url', 'publication_status',
        'ai_assisted', 'human_reviewed', 'risk_classification',
        'jurisdiction', 'product', 'language',
        'correction_state', 'correction_note',
        'withdrawal_state', 'status',
    ];

    protected array $casts = [
        'ai_assisted'    => 'boolean',
        'human_reviewed' => 'boolean',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function findByQuestionId(int $questionId): ?array
    {
        return $this->where('question_id', $questionId)->first();
    }

    public function listForQuestion(int $questionId): array
    {
        return $this->where('question_id', $questionId)->findAll();
    }

    public function listByPublicationStatus(string $status): array
    {
        return $this->where('publication_status', $status)->findAll();
    }

    public function getWithIdentityAndQuestion(int $id): ?array
    {
        return $this->db->table($this->table . ' a')
            ->select('a.*, i.slug AS identity_slug, i.display_name AS identity_name, i.badge_type, i.disclosure_template, q.title AS question_title, q.uuid AS question_uuid')
            ->join('reach_community_official_identities i', 'i.id = a.identity_id', 'left')
            ->join('reach_community_questions q', 'q.id = a.question_id', 'left')
            ->where('a.id', $id)
            ->get()
            ->getRowArray();
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

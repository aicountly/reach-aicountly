<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityAnswerApprovalModel extends Model
{
    protected $table         = 'reach_community_answer_approvals';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'answer_id', 'answer_version_number', 'version_checksum',
        'reach_approval_id', 'approved_by', 'approval_type',
        'outcome', 'reason', 'created_at',
    ];

    public function getLatestForAnswer(int $answerId): ?array
    {
        return $this->where('answer_id', $answerId)
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    public function listForAnswer(int $answerId): array
    {
        return $this->where('answer_id', $answerId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }
}

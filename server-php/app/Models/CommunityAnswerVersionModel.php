<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityAnswerVersionModel extends Model
{
    protected $table         = 'reach_community_answer_versions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'answer_id', 'version_number', 'content', 'excerpt', 'sources',
        'grounding_snapshot_id', 'generation_request_id', 'generation_run_id',
        'generation_artifact_id', 'prompt_version', 'model_route',
        'validation_results', 'risk_findings', 'moderation_decision',
        'reviewer_id', 'approver_id', 'approval_timestamp', 'checksum',
        'creation_reason', 'superseded_by', 'created_at',
    ];

    protected array $casts = [
        'sources'            => 'json-array',
        'validation_results' => 'json-array',
        'risk_findings'      => 'json-array',
    ];

    public function getLatestVersion(int $answerId): ?array
    {
        return $this->where('answer_id', $answerId)
            ->orderBy('version_number', 'DESC')
            ->first();
    }

    public function getVersion(int $answerId, int $versionNumber): ?array
    {
        return $this->where('answer_id', $answerId)
            ->where('version_number', $versionNumber)
            ->first();
    }

    public function listVersions(int $answerId): array
    {
        return $this->where('answer_id', $answerId)
            ->orderBy('version_number', 'DESC')
            ->findAll();
    }

    public function findByChecksum(string $checksum): ?array
    {
        return $this->where('checksum', $checksum)->first();
    }

    public function getNextVersionNumber(int $answerId): int
    {
        $row = $this->db->table($this->table)
            ->selectMax('version_number', 'max_version')
            ->where('answer_id', $answerId)
            ->get()
            ->getRowArray();

        return ((int) ($row['max_version'] ?? 0)) + 1;
    }
}

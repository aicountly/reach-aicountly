<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityModerationFindingModel extends Model
{
    protected $table         = 'reach_community_moderation_findings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'answer_version_id', 'question_id', 'finding_type', 'severity',
        'details', 'auto_action', 'override_by', 'override_reason',
        'override_at', 'status', 'created_at',
    ];

    protected array $casts = ['details' => 'json-array'];

    public function listOpenForVersion(int $versionId): array
    {
        return $this->where('answer_version_id', $versionId)
            ->where('status', 'open')
            ->findAll();
    }

    public function listOpenForQuestion(int $questionId): array
    {
        return $this->where('question_id', $questionId)
            ->where('status', 'open')
            ->findAll();
    }

    public function hasBlockingFindings(int $versionId): bool
    {
        $autoBlockingTypes = [
            'prompt_injection', 'malicious_html', 'unsafe_links',
            'personal_data', 'confidential_information',
            'hallucinated_features', 'impersonation_risk', 'prohibited_content',
        ];

        $count = $this->where('answer_version_id', $versionId)
            ->whereIn('finding_type', $autoBlockingTypes)
            ->where('status', 'open')
            ->countAllResults();

        return $count > 0;
    }
}

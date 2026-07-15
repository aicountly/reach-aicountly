<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshPolicyVersionModel extends Model
{
    protected $table      = 'reach_refresh_policy_versions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'policy_id', 'version_number', 'min_publication_age_days', 'comparison_window_days',
        'position_decline_threshold', 'impressions_decline_pct', 'clicks_decline_pct',
        'engagement_decline_pct', 'cooldown_days', 'required_evidence_sources',
        'risk_escalation_rules', 'approved_by', 'approved_at',
    ];

    public function getLatestForPolicy(int $policyId): ?array
    {
        return $this->where('policy_id', $policyId)->orderBy('version_number', 'DESC')->first();
    }
}

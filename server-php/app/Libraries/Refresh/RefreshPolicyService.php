<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Config\Permissions;
use App\Libraries\AuditLogger;
use App\Models\Refresh\RefreshPolicyModel;
use App\Models\Refresh\RefreshPolicyVersionModel;
use RuntimeException;

class RefreshPolicyService
{
    private const VERSION_FIELDS = [
        'min_publication_age_days', 'comparison_window_days',
        'position_decline_threshold', 'impressions_decline_pct',
        'clicks_decline_pct', 'engagement_decline_pct',
        'cooldown_days', 'required_evidence_sources', 'risk_escalation_rules',
    ];

    public function __construct(
        private RefreshPolicyModel        $policyModel,
        private RefreshPolicyVersionModel $versionModel,
        private AuditLogger               $auditLogger,
    ) {}

    public function createPolicy(int $tenantId, string $name, string $contentType, int $createdBy): array
    {
        $policy = [
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'content_type' => $contentType,
            'is_active'    => false,
            'created_by'   => $createdBy,
        ];
        $id = $this->policyModel->insert($policy);
        $saved = $this->policyModel->find($id);

        $this->auditLogger->log(
            userId:     $createdBy,
            action:     AuditLogger::REFRESH_POLICY_CREATED,
            entityType: 'refresh_policy',
            entityId:   $id,
            extra:      ['content_type' => $contentType],
        );

        return $saved;
    }

    public function createPolicyVersion(int $policyId, array $config, int $createdBy): array
    {
        $policy = $this->policyModel->find($policyId);
        if (! $policy) {
            throw new RuntimeException("Refresh policy {$policyId} not found");
        }

        $latest = $this->versionModel->getLatestForPolicy($policyId);
        $nextVersion = ($latest['version_number'] ?? 0) + 1;

        $versionData = array_intersect_key($config, array_flip(self::VERSION_FIELDS));
        $versionData['policy_id']      = $policyId;
        $versionData['version_number'] = $nextVersion;

        $id = $this->versionModel->insert($versionData);
        $saved = $this->versionModel->find($id);

        $this->auditLogger->log(
            userId:     $createdBy,
            action:     AuditLogger::REFRESH_POLICY_VERSION_CREATED,
            entityType: 'refresh_policy_version',
            entityId:   $id,
            extra:      ['policy_id' => $policyId, 'version' => $nextVersion],
        );

        return $saved;
    }

    public function approvePolicyVersion(int $versionId, int $approvedBy): array
    {
        $version = $this->versionModel->find($versionId);
        if (! $version) {
            throw new RuntimeException("Policy version {$versionId} not found");
        }
        if ($version['approved_by'] !== null) {
            throw new RuntimeException("Policy version {$versionId} is already approved");
        }

        $this->versionModel->update($versionId, [
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->log(
            userId:     $approvedBy,
            action:     AuditLogger::REFRESH_POLICY_VERSION_APPROVED,
            entityType: 'refresh_policy_version',
            entityId:   $versionId,
            extra:      ['policy_id' => $version['policy_id']],
        );

        return $this->versionModel->find($versionId);
    }

    public function activatePolicy(int $policyId, int $actorId): array
    {
        $policy  = $this->policyModel->find($policyId);
        $version = $this->versionModel->getLatestForPolicy($policyId);
        if (! $version || $version['approved_by'] === null) {
            throw new RuntimeException("Policy {$policyId} has no approved version — cannot activate");
        }

        $this->policyModel->update($policyId, ['is_active' => true]);
        return $this->policyModel->find($policyId);
    }

    public function getLatestApprovedVersion(int $policyId): ?array
    {
        $all = $this->versionModel->where('policy_id', $policyId)
                                   ->where('approved_by IS NOT NULL', null, false)
                                   ->orderBy('version_number', 'DESC')
                                   ->findAll();
        return $all[0] ?? null;
    }

    public function getActiveForTenant(int $tenantId, string $contentType = null): array
    {
        return $this->policyModel->getActiveForTenant($tenantId, $contentType);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshRecommendationModel extends Model
{
    protected $table      = 'reach_refresh_recommendations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'content_identity_id', 'policy_version_id',
        'evidence_snapshot_id', 'status', 'risk_classification', 'confidence',
        'effort_estimate', 'cooldown_until', 'superseded_by', 'assigned_to',
        'due_date', 'triage_notes',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getBacklogForTenant(int $tenantId, array $statuses = ['recommended']): array
    {
        return $this->where('tenant_id', $tenantId)
                    ->whereIn('status', $statuses)
                    ->orderBy('risk_classification', 'DESC')
                    ->orderBy('created_at', 'ASC')
                    ->findAll();
    }

    public function getActiveForContent(int $contentIdentityId): array
    {
        return $this->where('content_identity_id', $contentIdentityId)
                    ->whereNotIn('status', ['rejected', 'superseded', 'expired'])
                    ->findAll();
    }
}

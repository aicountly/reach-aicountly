<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshEvidenceSnapshotModel extends Model
{
    protected $table      = 'reach_refresh_evidence_snapshots';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'tenant_id', 'content_identity_id', 'policy_version_id',
        'evidence_date', 'window_days', 'evidence_packet', 'completeness_score',
        'missing_domains', 'freshness_state',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function getForContent(int $contentIdentityId, string $evidenceDate): ?array
    {
        return $this->where('content_identity_id', $contentIdentityId)
                    ->where('evidence_date', $evidenceDate)
                    ->orderBy('id', 'DESC')
                    ->first();
    }
}

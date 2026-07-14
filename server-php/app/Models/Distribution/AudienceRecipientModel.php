<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class AudienceRecipientModel extends Model
{
    protected $table      = 'reach_campaign_audience_recipients';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'snapshot_id', 'tenant_id', 'channel', 'channel_address_hash',
        'channel_address_masked', 'consent_status', 'suppressed',
        'suppression_reason', 'eligibility_status', 'eligibility_reason',
        'dedup_key', 'created_at',
    ];

    public function countEligible(int $snapshotId): int
    {
        return $this->where('snapshot_id', $snapshotId)
            ->where('eligibility_status', 'eligible')
            ->where('suppressed', false)
            ->countAllResults();
    }

    public function findEligiblePage(int $snapshotId, int $limit, int $offset): array
    {
        return $this->where('snapshot_id', $snapshotId)
            ->where('eligibility_status', 'eligible')
            ->where('suppressed', false)
            ->limit($limit, $offset)
            ->findAll();
    }
}

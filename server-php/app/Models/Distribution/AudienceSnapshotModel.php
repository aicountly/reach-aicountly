<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class AudienceSnapshotModel extends Model
{
    protected $table      = 'reach_campaign_audience_snapshots';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'campaign_id', 'campaign_version_id', 'tenant_id', 'channel',
        'recipient_count', 'eligible_count', 'suppressed_count',
        'snapshot_criteria', 'frozen_at', 'frozen_by', 'created_at',
    ];

    protected array $casts = ['snapshot_criteria' => '?json-array'];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function findByCampaign(int $campaignId, ?string $channel = null): ?array
    {
        $q = $this->where('campaign_id', $campaignId);
        if ($channel !== null) {
            $q = $q->where('channel', $channel);
        }
        return $q->orderBy('id', 'DESC')->first();
    }

    public function isFrozen(int $snapshotId): bool
    {
        $row = $this->select('frozen_at')->find($snapshotId);
        return $row !== null && $row['frozen_at'] !== null;
    }
}

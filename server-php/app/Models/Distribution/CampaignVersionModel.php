<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignVersionModel extends Model
{
    protected $table      = 'reach_campaign_versions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false; // immutable — manual created_at only

    protected $allowedFields = [
        'uuid', 'campaign_id', 'version_number', 'content_hash',
        'audience_snapshot_id', 'submitted_by', 'submitted_at',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'rejection_reason', 'created_by', 'created_at',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function findByCampaign(int $campaignId): array
    {
        return $this->where('campaign_id', $campaignId)->orderBy('version_number', 'DESC')->findAll();
    }

    public function nextVersionNumber(int $campaignId): int
    {
        $max = $this->selectMax('version_number', 'max_v')->where('campaign_id', $campaignId)->first();
        return (int) ($max['max_v'] ?? 0) + 1;
    }
}

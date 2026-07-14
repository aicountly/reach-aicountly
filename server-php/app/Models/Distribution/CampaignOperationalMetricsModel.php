<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignOperationalMetricsModel extends Model
{
    protected $table      = 'reach_campaign_operational_metrics';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'dispatch_id', 'tenant_id', 'channel',
        'queued', 'attempted', 'accepted', 'sent', 'delivered', 'read_count',
        'failed', 'bounced', 'complained', 'unsubscribed', 'suppressed', 'last_updated',
    ];

    public function incrementCounter(int $dispatchId, string $field, int $by = 1): void
    {
        $this->db->query(
            "UPDATE reach_campaign_operational_metrics SET {$field} = {$field} + ?, last_updated = NOW() WHERE dispatch_id = ?",
            [$by, $dispatchId]
        );
    }

    public function initForDispatch(int $dispatchId, int $tenantId, string $channel, int $queued): void
    {
        $existing = $this->where('dispatch_id', $dispatchId)->first();
        if ($existing === null) {
            $this->insert([
                'dispatch_id'  => $dispatchId,
                'tenant_id'    => $tenantId,
                'channel'      => $channel,
                'queued'       => $queued,
                'last_updated' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

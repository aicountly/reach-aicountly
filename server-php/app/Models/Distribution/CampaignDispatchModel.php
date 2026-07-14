<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignDispatchModel extends Model
{
    protected $table      = 'reach_campaign_dispatches';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'campaign_id', 'campaign_version_id', 'snapshot_id',
        'tenant_id', 'channel', 'status', 'connection_id', 'idempotency_key',
        'scheduled_at', 'started_at', 'completed_at',
        'total_recipients', 'sent_count', 'failed_count', 'suppressed_count',
        'lock_version', 'created_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }

    public function reserveForDispatch(int $id, int $expectedLockVersion): bool
    {
        $affected = $this->db->affectedRows();
        $this->db->query(
            "UPDATE reach_campaign_dispatches SET status = 'dispatching', lock_version = lock_version + 1, started_at = NOW() WHERE id = ? AND lock_version = ? AND status = 'queued'",
            [$id, $expectedLockVersion]
        );
        return $this->db->affectedRows() > 0;
    }
}

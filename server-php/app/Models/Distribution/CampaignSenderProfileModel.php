<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignSenderProfileModel extends Model
{
    protected $table      = 'reach_campaign_sender_profiles';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'channel', 'name', 'from_address', 'display_name',
        'reply_to', 'verified', 'provider', 'connection_id', 'dlt_header',
        'is_active', 'created_by',
    ];

    public function findByUuid(string $uuid, int $tenantId): ?array
    {
        return $this->where('uuid', $uuid)->where('tenant_id', $tenantId)->first();
    }
}

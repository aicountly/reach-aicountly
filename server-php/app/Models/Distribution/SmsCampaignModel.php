<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class SmsCampaignModel extends Model
{
    protected $table      = 'reach_sms_campaigns';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'campaign_id', 'tenant_id', 'sender_profile_id', 'template_version_id',
        'template_variables', 'dlt_entity_id', 'dlt_template_id', 'dlt_sender_id',
        'provider', 'connection_id', 'audience_filter', 'dispatch_id',
        'scheduled_at', 'sent_at', 'status', 'stats', 'created_by',
    ];

    protected array $casts = [
        'template_variables' => '?json-array',
        'audience_filter'    => '?json-array',
        'stats'              => '?json-array',
    ];

    public function findByUuid(string $uuid, ?int $tenantId = null): ?array
    {
        $q = $this->where('uuid', $uuid);
        if ($tenantId !== null) {
            $q = $q->where('tenant_id', $tenantId);
        }
        return $q->first();
    }
}

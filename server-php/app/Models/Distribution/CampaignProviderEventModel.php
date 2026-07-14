<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignProviderEventModel extends Model
{
    protected $table      = 'reach_campaign_provider_events';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'tenant_id', 'dispatch_id', 'attempt_id', 'provider',
        'connection_id', 'event_type', 'raw_event', 'normalised_status',
        'provider_event_id', 'received_at', 'processed_at', 'created_at',
    ];

    protected array $casts = ['raw_event' => '?json-array'];

    public function isDuplicate(string $provider, ?int $connectionId, string $providerEventId): bool
    {
        return $this->where('provider', $provider)
            ->where('connection_id', $connectionId)
            ->where('provider_event_id', $providerEventId)
            ->countAllResults() > 0;
    }
}

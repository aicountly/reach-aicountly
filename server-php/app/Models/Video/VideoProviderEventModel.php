<?php

declare(strict_types=1);

namespace App\Models\Video;

use CodeIgniter\Model;

class VideoProviderEventModel extends Model
{
    protected $table         = 'reach_video_provider_events';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'provider', 'provider_event_id', 'event_type', 'payload_hash',
    ];

    public function isDuplicate(string $provider, string $providerEventId): bool
    {
        return $this->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->countAllResults() > 0;
    }

    public function record(string $provider, string $providerEventId, string $eventType = '', string $payloadHash = ''): bool
    {
        $result = $this->db->query(
            "INSERT INTO {$this->table} (provider, provider_event_id, event_type, payload_hash)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (provider, provider_event_id) DO NOTHING",
            [$provider, $providerEventId, $eventType ?: null, $payloadHash ?: null]
        );
        return (bool) $result;
    }
}

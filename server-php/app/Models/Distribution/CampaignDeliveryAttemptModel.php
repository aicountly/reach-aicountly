<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class CampaignDeliveryAttemptModel extends Model
{
    protected $table      = 'reach_campaign_delivery_attempts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'dispatch_id', 'recipient_id', 'attempt_number', 'status',
        'provider', 'provider_message_id', 'remote_url', 'failure_class',
        'failure_detail', 'provider_latency_ms', 'idempotency_key',
        'accepted_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at', 'created_at',
    ];

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }

    public function findByProviderMessageId(string $messageId): ?array
    {
        return $this->where('provider_message_id', $messageId)->first();
    }
}

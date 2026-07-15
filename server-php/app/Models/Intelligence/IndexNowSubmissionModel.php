<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class IndexNowSubmissionModel extends Model
{
    protected $table      = 'reach_indexnow_submissions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'uuid', 'tenant_id', 'content_identity_id', 'url', 'provider_endpoint',
        'idempotency_key', 'status', 'max_attempts', 'attempt_count',
        'submitted_at', 'next_retry_at', 'completed_at', 'triggered_by',
    ];

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->first();
    }

    public function findByIdempotencyKey(int $tenantId, string $key): ?array
    {
        return $this->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
    }

    public function getPendingRetries(): array
    {
        return $this->where('status', 'retrying')
                    ->where('next_retry_at <=', date('Y-m-d H:i:s'))
                    ->findAll();
    }
}

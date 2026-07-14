<?php

namespace App\Models;

use CodeIgniter\Model;

class CommunityDeploymentModel extends Model
{
    protected $table         = 'reach_community_deployments';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'uuid', 'answer_id', 'answer_version_number', 'version_checksum',
        'operation', 'idempotency_key', 'status', 'attempt_count',
        'max_attempts', 'last_error', 'last_error_category', 'next_retry_at',
        'public_answer_id', 'public_url', 'response_checksum', 'deployed_at',
    ];

    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return null;
        }
        return $this->where('uuid', $uuid)->first();
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }

    public function listPendingRetries(): array
    {
        return $this->where('status', 'retrying')
            ->where('next_retry_at <=', date('Y-m-d H:i:s'))
            ->where('attempt_count <', $this->db->table($this->table)->getCompiledSelect())
            ->findAll();
    }

    public function listRetryDue(): array
    {
        return $this->db->table($this->table)
            ->where('status', 'retrying')
            ->where('next_retry_at <=', date('Y-m-d H:i:s'))
            ->where('attempt_count < max_attempts', null, false)
            ->get()
            ->getResultArray();
    }

    public function listForAnswer(int $answerId): array
    {
        return $this->where('answer_id', $answerId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class ChannelConsentModel extends Model
{
    protected $table      = 'reach_channel_consents';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'tenant_id', 'subject_type', 'subject_id', 'channel',
        'purpose', 'status', 'source', 'proof_reference',
        'captured_at', 'captured_by', 'revoked_at', 'expires_at', 'created_at',
    ];

    public function findLatest(int $tenantId, string $subjectType, int $subjectId, string $channel, string $purpose = 'marketing'): ?array
    {
        return $this->where('tenant_id', $tenantId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->orderBy('captured_at', 'DESC')
            ->first();
    }

    public function isGranted(int $tenantId, string $subjectType, int $subjectId, string $channel): bool
    {
        $row = $this->findLatest($tenantId, $subjectType, $subjectId, $channel);
        return $row !== null && $row['status'] === 'granted'
            && ($row['expires_at'] === null || strtotime($row['expires_at']) > time());
    }
}

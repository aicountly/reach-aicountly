<?php

namespace App\Models;

use CodeIgniter\Model;

class SessionModel extends Model
{
    protected $table         = 'reach_sessions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'user_id', 'token_hash', 'expires_at', 'revoked_at',
        'ip_address', 'user_agent', 'created_at',
    ];

    public function createFromToken(int $userId, string $token, int $ttlSecs, ?string $ip, ?string $userAgent): int
    {
        $this->insert([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSecs),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->insertID();
    }

    public function findActiveByTokenHash(string $hash): ?array
    {
        $row = $this->where('token_hash', $hash)
            ->where('revoked_at', null)
            ->where('expires_at >', gmdate('Y-m-d H:i:s'))
            ->first();
        return $row ?: null;
    }

    public function revoke(int $sessionId): void
    {
        $this->update($sessionId, ['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }
}

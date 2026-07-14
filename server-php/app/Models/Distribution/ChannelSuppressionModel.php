<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class ChannelSuppressionModel extends Model
{
    protected $table      = 'reach_channel_suppressions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'uuid', 'tenant_id', 'channel', 'address_hash', 'address_masked',
        'reason', 'source', 'suppressed_at', 'suppressed_by', 'expires_at', 'created_at',
    ];

    /**
     * Check if an address hash is suppressed.
     * Address hash = sha256(tenant_id . ':' . channel . ':' . strtolower(trim($address)))
     */
    public function isSuppressed(int $tenantId, string $channel, string $addressHash): bool
    {
        $row = $this->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where('address_hash', $addressHash)
            ->first();

        if ($row === null) {
            return false;
        }

        if ($row['expires_at'] !== null && strtotime($row['expires_at']) < time()) {
            return false;
        }

        return true;
    }

    public static function hashAddress(int $tenantId, string $channel, string $address): string
    {
        return hash('sha256', $tenantId . ':' . $channel . ':' . strtolower(trim($address)));
    }

    public static function maskAddress(string $address): string
    {
        if (str_contains($address, '@')) {
            [$local, $domain] = explode('@', $address, 2);
            return substr($local, 0, 2) . '***@' . $domain;
        }
        return substr($address, 0, -4) . '****';
    }
}

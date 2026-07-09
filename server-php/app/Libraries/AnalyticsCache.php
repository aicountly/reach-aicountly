<?php

namespace App\Libraries;

use Config\Database;

/**
 * Cached GA4 report payloads (avoids repeated Data API calls).
 */
final class AnalyticsCache
{
    public function get(string $key, string $hash): ?array
    {
        $db   = Database::connect();
        $row  = $db->table('reach_analytics_cache')
            ->select('data_json')
            ->where('report_key', $key)
            ->where('params_hash', $hash)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get(1)
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) ($row['data_json'] ?? ''), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function set(string $key, string $hash, array $data, int $ttlSeconds): void
    {
        $db      = Database::connect();
        $expires = date('Y-m-d H:i:s', time() + max(60, $ttlSeconds));
        $payload = json_encode($data, JSON_THROW_ON_ERROR);

        $db->query(
            'INSERT INTO reach_analytics_cache (report_key, params_hash, data_json, fetched_at, expires_at)
             VALUES (?, ?, ?::jsonb, NOW(), ?)
             ON CONFLICT (report_key, params_hash) DO UPDATE SET
                data_json = EXCLUDED.data_json,
                fetched_at = NOW(),
                expires_at = EXCLUDED.expires_at',
            [$key, $hash, $payload, $expires]
        );
    }
}

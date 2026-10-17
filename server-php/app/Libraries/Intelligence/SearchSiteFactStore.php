<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Writer for the site-wide Search Console tables (reach_search_site_facts,
 * reach_search_site_daily). Unlike reach_search_metric_facts, nothing is
 * filtered: every row Google returns for the property is kept, so the Search
 * page and the daily report can show the whole site, not only Reach's blogs.
 *
 * Writes are batched multi-row upserts — a single Search Console page can be
 * 25 000 rows, and one INSERT per row is far too slow on shared hosting.
 */
class SearchSiteFactStore
{
    private const CHUNK = 400;

    private ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db;
    }

    public function isAvailable(): bool
    {
        try {
            return SchemaGuard::hasTable($this->db(), 'reach_search_site_facts')
                && SchemaGuard::hasTable($this->db(), 'reach_search_site_daily');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows connector-normalised rows (metric_date, page_url, query, device, country, clicks, impressions, ctr, avg_position)
     * @return int rows written
     */
    public function upsertFacts(int $connectionId, string $siteProperty, ?int $runId, array $rows): int
    {
        $unique = [];
        foreach ($rows as $row) {
            $date = (string) ($row['metric_date'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $page    = trim((string) ($row['page_url'] ?? ''));
            $query   = trim((string) ($row['query'] ?? ''));
            $device  = self::device($row['device'] ?? null);
            $country = self::country($row['country'] ?? null);
            // A duplicate key inside one INSERT … ON CONFLICT statement is an
            // error in Postgres ("cannot affect row a second time"), so the
            // batch is de-duplicated on the unique key first.
            $unique[$date . "\x1f" . $page . "\x1f" . $query . "\x1f" . $device . "\x1f" . $country] = [
                $connectionId, $siteProperty, $runId, $date, $page, $query, $device, $country,
                (int) ($row['clicks'] ?? 0), (int) ($row['impressions'] ?? 0),
                isset($row['ctr']) ? round((float) $row['ctr'], 6) : null,
                isset($row['avg_position']) ? round((float) $row['avg_position'], 2) : null,
            ];
        }

        $written = 0;
        foreach (array_chunk(array_values($unique), self::CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,NOW())'));
            $this->db()->query(
                "INSERT INTO reach_search_site_facts
                    (connection_id, site_property, ingestion_run_id, metric_date, page_url, query, device, country,
                     clicks, impressions, ctr, avg_position, collected_at)
                 VALUES {$placeholders}
                 ON CONFLICT (connection_id, metric_date, page_url, query, device, country) DO UPDATE SET
                    site_property    = EXCLUDED.site_property,
                    ingestion_run_id = EXCLUDED.ingestion_run_id,
                    clicks           = EXCLUDED.clicks,
                    impressions      = EXCLUDED.impressions,
                    ctr              = EXCLUDED.ctr,
                    avg_position     = EXCLUDED.avg_position,
                    collected_at     = NOW()",
                array_merge(...$chunk)
            );
            $written += count($chunk);
        }

        return $written;
    }

    /**
     * Property-level totals (rows fetched with dimensions date + device only).
     *
     * @param list<array<string, mixed>> $rows
     */
    public function upsertDaily(int $connectionId, string $siteProperty, ?int $runId, array $rows): int
    {
        $unique = [];
        foreach ($rows as $row) {
            $date = (string) ($row['metric_date'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $device = self::device($row['device'] ?? null);
            $key    = $date . "\x1f" . $device;
            if (isset($unique[$key])) {
                // Same day+device twice (a provider that ignored the requested
                // dimensions): accumulate rather than drop.
                $prev        = $unique[$key];
                $clicks      = $prev[4] + (int) ($row['clicks'] ?? 0);
                $impressions = $prev[5] + (int) ($row['impressions'] ?? 0);
                $position    = $impressions > 0
                    ? round((($prev[7] ?? 0) * $prev[5] + (float) ($row['avg_position'] ?? 0) * (int) ($row['impressions'] ?? 0)) / $impressions, 2)
                    : null;
                $unique[$key] = [$connectionId, $siteProperty, $runId, $date, $clicks, $impressions,
                    $impressions > 0 ? round($clicks / $impressions, 6) : null, $position, $device];
                continue;
            }
            $unique[$key] = [
                $connectionId, $siteProperty, $runId, $date,
                (int) ($row['clicks'] ?? 0), (int) ($row['impressions'] ?? 0),
                isset($row['ctr']) ? round((float) $row['ctr'], 6) : null,
                isset($row['avg_position']) ? round((float) $row['avg_position'], 2) : null,
                $device,
            ];
        }

        $written = 0;
        foreach (array_chunk(array_values($unique), self::CHUNK) as $chunk) {
            $binds = [];
            foreach ($chunk as $r) {
                // column order: connection_id, site_property, run, date, device, clicks, impressions, ctr, position
                $binds[] = [$r[0], $r[1], $r[2], $r[3], $r[8], $r[4], $r[5], $r[6], $r[7]];
            }
            $placeholders = implode(',', array_fill(0, count($binds), '(?,?,?,?,?,?,?,?,?,NOW())'));
            $this->db()->query(
                "INSERT INTO reach_search_site_daily
                    (connection_id, site_property, ingestion_run_id, metric_date, device,
                     clicks, impressions, ctr, avg_position, collected_at)
                 VALUES {$placeholders}
                 ON CONFLICT (connection_id, metric_date, device) DO UPDATE SET
                    site_property    = EXCLUDED.site_property,
                    ingestion_run_id = EXCLUDED.ingestion_run_id,
                    clicks           = EXCLUDED.clicks,
                    impressions      = EXCLUDED.impressions,
                    ctr              = EXCLUDED.ctr,
                    avg_position     = EXCLUDED.avg_position,
                    collected_at     = NOW()",
                array_merge(...$binds)
            );
            $written += count($binds);
        }

        return $written;
    }

    private static function device(mixed $value): string
    {
        $device = strtoupper(trim((string) $value));

        return in_array($device, ['DESKTOP', 'MOBILE', 'TABLET', 'SMARTTV'], true) ? $device : 'UNKNOWN';
    }

    private static function country(mixed $value): string
    {
        $country = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2,3}$/', $country) ? $country : 'ZZZ';
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }
}

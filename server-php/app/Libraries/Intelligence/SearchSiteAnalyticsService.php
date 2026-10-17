<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Read side of the site-wide Search Console tables. Every figure is an
 * aggregate of ingested rows; when nothing has been ingested the answers are
 * empty/zero with `available = false`, never estimates.
 *
 * Positions are impression-weighted: a plain mean over rows would let a
 * query with 2 impressions at position 90 count as much as one with 2 000
 * impressions at position 3.
 */
class SearchSiteAnalyticsService
{
    private ?BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?int $connectionId = null)
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

    /** Freshest day with any site-wide data, or null. */
    public function latestDate(): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }
        [$where, $binds] = $this->scope('1=1');
        $daily = $this->db()->query("SELECT MAX(metric_date) AS d FROM reach_search_site_daily WHERE {$where}", $binds)->getRowArray();
        $facts = $this->db()->query("SELECT MAX(metric_date) AS d FROM reach_search_site_facts WHERE {$where}", $binds)->getRowArray();
        $dates = array_filter([(string) ($daily['d'] ?? ''), (string) ($facts['d'] ?? '')]);

        return $dates === [] ? null : max($dates);
    }

    /**
     * Totals per day. Uses the property-level daily table; for days that only
     * have row-level facts (ingested before the daily pass existed) it falls
     * back to summing those rows and marks the day `source = facts`.
     *
     * @return list<array{date:string, clicks:int, impressions:int, ctr:?float, avg_position:?float, source:string}>
     */
    public function daily(string $from, string $to): array
    {
        if (! $this->isAvailable()) {
            return [];
        }
        [$where, $binds] = $this->scope('metric_date BETWEEN ? AND ?', [$from, $to]);

        $rows = $this->db()->query(
            "SELECT metric_date::text AS date, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
             FROM reach_search_site_daily WHERE {$where}
             GROUP BY metric_date ORDER BY metric_date",
            $binds
        )->getResultArray();
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['date']] = self::shape($row) + ['source' => 'daily'];
        }

        $factRows = $this->db()->query(
            "SELECT metric_date::text AS date, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
             FROM reach_search_site_facts WHERE {$where}
             GROUP BY metric_date ORDER BY metric_date",
            $binds
        )->getResultArray();
        foreach ($factRows as $row) {
            if (! isset($byDate[$row['date']])) {
                $byDate[$row['date']] = self::shape($row) + ['source' => 'facts'];
            }
        }

        ksort($byDate);

        return array_values($byDate);
    }

    /**
     * @return array{clicks:int, impressions:int, ctr:?float, avg_position:?float, days:int, latest_date:?string}
     */
    public function totals(string $from, string $to): array
    {
        $days        = $this->daily($from, $to);
        $clicks      = array_sum(array_column($days, 'clicks'));
        $impressions = array_sum(array_column($days, 'impressions'));
        $weighted    = 0.0;
        foreach ($days as $d) {
            $weighted += (float) ($d['avg_position'] ?? 0) * $d['impressions'];
        }

        return [
            'clicks'       => (int) $clicks,
            'impressions'  => (int) $impressions,
            'ctr'          => $impressions > 0 ? round($clicks / $impressions, 6) : null,
            'avg_position' => $impressions > 0 ? round($weighted / $impressions, 2) : null,
            'days'         => count($days),
            'latest_date'  => $days === [] ? null : $days[count($days) - 1]['date'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function topQueries(string $from, string $to, int $limit = 25): array
    {
        return $this->top('query', $from, $to, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function topPages(string $from, string $to, int $limit = 25): array
    {
        return $this->top('page_url', $from, $to, $limit);
    }

    /**
     * How many queries sit in each position band over the window, with the
     * clicks and impressions those queries earned.
     *
     * @return list<array{bucket:string, queries:int, clicks:int, impressions:int}>
     */
    public function positionBuckets(string $from, string $to): array
    {
        $buckets = [
            '1-3'   => ['bucket' => '1-3', 'queries' => 0, 'clicks' => 0, 'impressions' => 0],
            '4-10'  => ['bucket' => '4-10', 'queries' => 0, 'clicks' => 0, 'impressions' => 0],
            '11-20' => ['bucket' => '11-20', 'queries' => 0, 'clicks' => 0, 'impressions' => 0],
            '21-50' => ['bucket' => '21-50', 'queries' => 0, 'clicks' => 0, 'impressions' => 0],
            '51+'   => ['bucket' => '51+', 'queries' => 0, 'clicks' => 0, 'impressions' => 0],
        ];
        if (! $this->isAvailable()) {
            return array_values($buckets);
        }
        [$where, $binds] = $this->scope("metric_date BETWEEN ? AND ? AND query <> ''", [$from, $to]);

        $rows = $this->db()->query(
            "SELECT CASE
                        WHEN pos <= 3  THEN '1-3'
                        WHEN pos <= 10 THEN '4-10'
                        WHEN pos <= 20 THEN '11-20'
                        WHEN pos <= 50 THEN '21-50'
                        ELSE '51+' END AS bucket,
                    COUNT(*) AS queries, SUM(clicks) AS clicks, SUM(impressions) AS impressions
             FROM (
                SELECT query, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                       SUM(avg_position * impressions) / NULLIF(SUM(impressions), 0) AS pos
                FROM reach_search_site_facts WHERE {$where}
                GROUP BY query HAVING SUM(impressions) > 0
             ) q
             GROUP BY 1",
            $binds
        )->getResultArray();

        foreach ($rows as $row) {
            $buckets[$row['bucket']] = [
                'bucket'      => $row['bucket'],
                'queries'     => (int) $row['queries'],
                'clicks'      => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
            ];
        }

        return array_values($buckets);
    }

    /**
     * Queries whose position moved most on $date against their average over
     * [$baseFrom, $baseTo]. Only queries with impressions in both periods
     * count — a query that simply appeared is not a "mover".
     *
     * @return array{improved: list<array<string,mixed>>, declined: list<array<string,mixed>>}
     */
    public function movers(string $date, string $baseFrom, string $baseTo, int $limit = 5, int $minImpressions = 5): array
    {
        if (! $this->isAvailable()) {
            return ['improved' => [], 'declined' => []];
        }
        $scope = $this->connectionId !== null ? ' AND connection_id = ' . (int) $this->connectionId : '';

        $rows = $this->db()->query(
            "WITH cur AS (
                SELECT query, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                       SUM(avg_position * impressions) / NULLIF(SUM(impressions), 0) AS pos
                FROM reach_search_site_facts
                WHERE metric_date = ? AND query <> ''{$scope}
                GROUP BY query HAVING SUM(impressions) >= ?
             ), base AS (
                SELECT query, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                       SUM(avg_position * impressions) / NULLIF(SUM(impressions), 0) AS pos
                FROM reach_search_site_facts
                WHERE metric_date BETWEEN ? AND ? AND query <> ''{$scope}
                GROUP BY query HAVING SUM(impressions) > 0
             )
             SELECT cur.query, cur.clicks, cur.impressions, ROUND(cur.pos, 1) AS position,
                    ROUND(base.pos, 1) AS previous_position, ROUND(base.pos - cur.pos, 1) AS change
             FROM cur JOIN base USING (query)
             WHERE cur.pos IS NOT NULL AND base.pos IS NOT NULL",
            [$date, $minImpressions, $baseFrom, $baseTo]
        )->getResultArray();

        $rows = array_map(static fn (array $r): array => [
            'query'             => $r['query'],
            'clicks'            => (int) $r['clicks'],
            'impressions'       => (int) $r['impressions'],
            'position'          => (float) $r['position'],
            'previous_position' => (float) $r['previous_position'],
            'change'            => (float) $r['change'],
        ], $rows);

        $improved = array_values(array_filter($rows, static fn ($r) => $r['change'] > 0));
        $declined = array_values(array_filter($rows, static fn ($r) => $r['change'] < 0));
        usort($improved, static fn ($a, $b) => $b['change'] <=> $a['change']);
        usort($declined, static fn ($a, $b) => $a['change'] <=> $b['change']);

        return [
            'improved' => array_slice($improved, 0, $limit),
            'declined' => array_slice($declined, 0, $limit),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function top(string $column, string $from, string $to, int $limit): array
    {
        if (! $this->isAvailable()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        [$where, $binds] = $this->scope("metric_date BETWEEN ? AND ? AND {$column} <> ''", [$from, $to]);

        $rows = $this->db()->query(
            "SELECT {$column} AS dimension_value, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
             FROM reach_search_site_facts WHERE {$where}
             GROUP BY {$column}
             ORDER BY SUM(clicks) DESC, SUM(impressions) DESC, {$column}
             LIMIT {$limit}",
            $binds
        )->getResultArray();

        return array_map(static fn (array $r): array => ['dimension_value' => $r['dimension_value']] + self::shape($r), $rows);
    }

    /**
     * @param list<mixed> $binds
     * @return array{0:string, 1:list<mixed>}
     */
    private function scope(string $where, array $binds = []): array
    {
        if ($this->connectionId !== null) {
            $where  .= ' AND connection_id = ?';
            $binds[] = $this->connectionId;
        }

        return [$where, $binds];
    }

    /** @param array<string, mixed> $row */
    private static function shape(array $row): array
    {
        $clicks      = (int) ($row['clicks'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);

        return [
            'date'         => $row['date'] ?? null,
            'clicks'       => $clicks,
            'impressions'  => $impressions,
            'ctr'          => $impressions > 0 ? round($clicks / $impressions, 6) : null,
            'avg_position' => isset($row['avg_position']) && $row['avg_position'] !== null ? round((float) $row['avg_position'], 2) : null,
        ];
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }
}

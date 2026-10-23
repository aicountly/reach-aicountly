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

    /** Dimensions a site-wide slice can be grouped or filtered by → their fact column. */
    public const EXPLORE_DIMENSIONS = [
        'date'    => 'metric_date',
        'query'   => 'query',
        'page'    => 'page_url',
        'device'  => 'device',
        'country' => 'country',
    ];

    /** Position bands, as positionBuckets() names them → [min exclusive, max inclusive]. */
    public const POSITION_BANDS = [
        '1-3'   => [0, 3],
        '4-10'  => [3, 10],
        '11-20' => [10, 20],
        '21-50' => [20, 50],
        '51+'   => [50, null],
    ];

    private const EXPLORE_SORTS = ['clicks', 'impressions', 'ctr', 'position', 'key'];

    /**
     * One step of a drill-down: the row-level facts in [$from, $to] narrowed by
     * every filter already chosen (date, query, page, device, country, and the
     * query's position band over the window), grouped by one more dimension.
     * Each returned row is itself a filter value for the next step, so a viewer
     * can keep narrowing — day → query → page → device — until one fact is left.
     *
     * Totals are summed from the same narrowed facts, so the rows always add up
     * to them. They can sit below the property-level daily totals: Google drops
     * anonymised queries from query-level rows.
     *
     * @param array<string, string> $filters keys of EXPLORE_DIMENSIONS plus `bucket`
     * @param array{sort?:string, dir?:string, limit?:int, offset?:int, search?:string} $opts
     * @return array{group_by:string, rows:list<array<string,mixed>>, total_rows:int, totals:array<string,mixed>, filters:array<string,string>}
     */
    public function explore(string $groupBy, string $from, string $to, array $filters = [], array $opts = []): array
    {
        if (! isset(self::EXPLORE_DIMENSIONS[$groupBy])) {
            throw new \InvalidArgumentException("Unknown dimension `{$groupBy}`.");
        }
        $filters = array_filter(
            array_intersect_key($filters, self::EXPLORE_DIMENSIONS + ['bucket' => true]),
            static fn ($v): bool => is_string($v) && $v !== ''
        );
        if (isset($filters['bucket']) && ! isset(self::POSITION_BANDS[$filters['bucket']])) {
            throw new \InvalidArgumentException("Unknown position band `{$filters['bucket']}`.");
        }

        $empty = [
            'group_by'   => $groupBy,
            'rows'       => [],
            'total_rows' => 0,
            'totals'     => array_diff_key(self::shape([]), ['date' => true]) + ['rows' => 0],
            'filters'    => $filters,
        ];
        if (! $this->isAvailable()) {
            return $empty;
        }

        [$where, $binds] = $this->scope('metric_date BETWEEN ? AND ?', [$from, $to]);
        foreach ($filters as $key => $value) {
            if ($key === 'bucket') {
                continue;
            }
            $where  .= ' AND ' . self::EXPLORE_DIMENSIONS[$key] . ($key === 'date' ? ' = ?::date' : ' = ?');
            $binds[] = $value;
        }
        if (isset($filters['bucket'])) {
            // The band is a property of the query over the whole window (as in
            // positionBuckets()), not of each day's row.
            [$min, $max] = self::POSITION_BANDS[$filters['bucket']];
            [$bandWhere, $bandBinds] = $this->scope("metric_date BETWEEN ? AND ? AND query <> ''", [$from, $to]);
            $having = 'SUM(avg_position * impressions) / NULLIF(SUM(impressions), 0) > ' . (int) $min
                . ($max !== null ? ' AND SUM(avg_position * impressions) / NULLIF(SUM(impressions), 0) <= ' . (int) $max : '');
            $where .= " AND query IN (SELECT query FROM reach_search_site_facts WHERE {$bandWhere} GROUP BY query HAVING {$having})";
            $binds  = array_merge($binds, $bandBinds);
        }

        $column = self::EXPLORE_DIMENSIONS[$groupBy];
        $key    = $groupBy === 'date' ? 'metric_date::text' : $column;
        $search = trim((string) ($opts['search'] ?? ''));
        $groupWhere = $where;
        $groupBinds = $binds;
        if ($search !== '') {
            $groupWhere  .= " AND {$key} ILIKE ?";
            $groupBinds[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        $sort  = in_array($opts['sort'] ?? '', self::EXPLORE_SORTS, true) ? $opts['sort'] : ($groupBy === 'date' ? 'key' : 'clicks');
        $dir   = strtolower((string) ($opts['dir'] ?? '')) === 'asc' ? 'ASC' : (strtolower((string) ($opts['dir'] ?? '')) === 'desc' ? 'DESC' : null);
        $dir ??= match ($sort) {
            'position' => 'ASC',
            'key'      => $groupBy === 'date' ? 'DESC' : 'ASC',
            default    => 'DESC',
        };
        $order = match ($sort) {
            'impressions' => "SUM(impressions) {$dir}, SUM(clicks) DESC",
            'ctr'         => "SUM(clicks)::numeric / NULLIF(SUM(impressions), 0) {$dir} NULLS LAST, SUM(impressions) DESC",
            'position'    => "SUM(avg_position * impressions) / NULLIF(SUM(impressions), 0) {$dir} NULLS LAST, SUM(impressions) DESC",
            'key'         => "{$key} {$dir}",
            default       => "SUM(clicks) {$dir}, SUM(impressions) DESC",
        };
        $limit  = max(1, min(500, (int) ($opts['limit'] ?? 50)));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $rows = $this->db()->query(
            "SELECT {$key} AS dimension_value, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position,
                    COUNT(DISTINCT metric_date) AS days
             FROM reach_search_site_facts WHERE {$groupWhere}
             GROUP BY {$key}
             ORDER BY {$order}, {$key}
             LIMIT {$limit} OFFSET {$offset}",
            $groupBinds
        )->getResultArray();

        $count = $this->db()->query(
            "SELECT COUNT(DISTINCT {$key}) AS c FROM reach_search_site_facts WHERE {$groupWhere}",
            $groupBinds
        )->getRowArray();

        $totals = $this->db()->query(
            "SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position,
                    COUNT(*) AS rows
             FROM reach_search_site_facts WHERE {$where}",
            $binds
        )->getRowArray() ?? [];

        return [
            'group_by'   => $groupBy,
            'rows'       => array_map(static function (array $r): array {
                $shaped = ['dimension_value' => (string) $r['dimension_value']] + self::shape($r);
                unset($shaped['date']);

                return $shaped + ['days' => (int) $r['days']];
            }, $rows),
            'total_rows' => (int) ($count['c'] ?? 0),
            'totals'     => array_diff_key(self::shape($totals), ['date' => true]) + ['rows' => (int) ($totals['rows'] ?? 0)],
            'filters'    => $filters,
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

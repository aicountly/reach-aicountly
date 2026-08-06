<?php

declare(strict_types=1);

namespace App\Models\Intelligence;

use CodeIgniter\Model;

class SearchMetricFactModel extends Model
{
    protected $table      = 'reach_search_metric_facts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'content_identity_id', 'connection_id', 'ingestion_run_id', 'metric_date',
        'query', 'page_url', 'device', 'country', 'clicks', 'impressions',
        'ctr', 'avg_position', 'provider_freshness_at', 'collected_at', 'is_revised',
    ];

    public function getForContent(int $contentIdentityId, string $fromDate, string $toDate): array
    {
        return $this->where('content_identity_id', $contentIdentityId)
                    ->where('metric_date >=', $fromDate)
                    ->where('metric_date <=', $toDate)
                    ->orderBy('metric_date', 'DESC')
                    ->findAll();
    }

    /**
     * Insert or restate one Search Console fact.
     *
     * Search Console keeps revising a day's figures for some time after it is
     * first reported, and the ingest job deliberately re-reads a rolling
     * lookback window. The previous version refused any update once a row had
     * been revised even once, which froze each day at its second reading; the
     * conflict target's nullable columns meant it usually never matched at all.
     * Both are fixed: every re-read now restates the row, and `is_revised`
     * records that the figures changed after first capture rather than gating
     * the write.
     */
    public function upsertFact(array $data): bool
    {
        $sql = "INSERT INTO reach_search_metric_facts
                (content_identity_id, connection_id, ingestion_run_id, metric_date, query, page_url,
                 device, country, clicks, impressions, ctr, avg_position, provider_freshness_at, collected_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON CONFLICT (content_identity_id, connection_id, metric_date, query, page_url, device, country)
                DO UPDATE SET
                    ingestion_run_id = EXCLUDED.ingestion_run_id,
                    clicks = EXCLUDED.clicks,
                    impressions = EXCLUDED.impressions,
                    ctr = EXCLUDED.ctr,
                    avg_position = EXCLUDED.avg_position,
                    provider_freshness_at = EXCLUDED.provider_freshness_at,
                    collected_at = NOW(),
                    is_revised = reach_search_metric_facts.is_revised
                        OR reach_search_metric_facts.clicks      IS DISTINCT FROM EXCLUDED.clicks
                        OR reach_search_metric_facts.impressions IS DISTINCT FROM EXCLUDED.impressions";

        $this->db->query($sql, [
            $data['content_identity_id'], $data['connection_id'], $data['ingestion_run_id'] ?? null,
            $data['metric_date'], self::normaliseQuery($data['query'] ?? null), $data['page_url'],
            self::normaliseDevice($data['device'] ?? null), self::normaliseCountry($data['country'] ?? null),
            $data['clicks'], $data['impressions'], $data['ctr'] ?? null, $data['avg_position'] ?? null,
            $data['provider_freshness_at'] ?? null,
        ]);
        return true;
    }

    /**
     * Clicks/impressions/position rolled up over a date range, grouped by one
     * dimension. Used by the Search Intelligence endpoints — position is
     * impression-weighted because a plain average over rows would over-weight
     * long-tail queries with a handful of impressions.
     *
     * @return list<array<string, mixed>>
     */
    public function aggregate(string $dimension, string $from, string $to, int $limit = 100): array
    {
        $column = match ($dimension) {
            'page', 'page_url' => 'page_url',
            'date'             => 'metric_date',
            'device'           => 'device',
            'country'          => 'country',
            default            => 'query',
        };

        $order = $column === 'metric_date' ? 'metric_date ASC' : 'clicks DESC, impressions DESC';
        $where = $column === 'query' ? "AND query <> ''" : '';

        $sql = "SELECT {$column} AS dimension_value,
                       SUM(clicks)      AS clicks,
                       SUM(impressions) AS impressions,
                       CASE WHEN SUM(impressions) > 0
                            THEN SUM(clicks)::numeric / SUM(impressions) END AS ctr,
                       CASE WHEN SUM(impressions) > 0
                            THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
                FROM reach_search_metric_facts
                WHERE metric_date >= ? AND metric_date <= ? {$where}
                GROUP BY {$column}
                ORDER BY {$order}
                LIMIT ?";

        $rows = $this->db->query($sql, [$from, $to, max(1, min($limit, 1000))])->getResultArray();

        return array_map(static fn (array $r): array => [
            'dimension'       => $column,
            'dimension_value' => $r['dimension_value'],
            $column           => $r['dimension_value'],
            'clicks'          => (int) $r['clicks'],
            'impressions'     => (int) $r['impressions'],
            'ctr'             => $r['ctr'] === null ? null : (float) $r['ctr'],
            'avg_position'    => $r['avg_position'] === null ? null : round((float) $r['avg_position'], 2),
        ], $rows);
    }

    /**
     * Range totals, plus how fresh the underlying facts are.
     *
     * @return array<string, mixed>
     */
    public function totals(string $from, string $to): array
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(clicks), 0)      AS clicks,
                    COALESCE(SUM(impressions), 0) AS impressions,
                    COUNT(*)                      AS rows_count,
                    COUNT(DISTINCT query) FILTER (WHERE query <> '') AS queries,
                    COUNT(DISTINCT page_url)      AS pages,
                    MAX(metric_date)              AS latest_metric_date,
                    MAX(collected_at)             AS last_collected_at,
                    CASE WHEN SUM(impressions) > 0
                         THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
             FROM reach_search_metric_facts
             WHERE metric_date >= ? AND metric_date <= ?",
            [$from, $to]
        )->getRowArray() ?: [];

        $clicks      = (int) ($row['clicks'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);

        return [
            'clicks'             => $clicks,
            'impressions'        => $impressions,
            'ctr'                => $impressions > 0 ? $clicks / $impressions : null,
            'avg_position'       => isset($row['avg_position']) && $row['avg_position'] !== null
                ? round((float) $row['avg_position'], 2)
                : null,
            'rows'               => (int) ($row['rows_count'] ?? 0),
            'queries'            => (int) ($row['queries'] ?? 0),
            'pages'              => (int) ($row['pages'] ?? 0),
            'latest_metric_date' => $row['latest_metric_date'] ?? null,
            'last_collected_at'  => $row['last_collected_at'] ?? null,
        ];
    }

    public static function normaliseQuery(?string $query): string
    {
        return trim((string) $query);
    }

    public static function normaliseDevice(?string $device): string
    {
        $device = strtoupper(trim((string) $device));

        return in_array($device, ['DESKTOP', 'MOBILE', 'TABLET', 'SMARTTV'], true) ? $device : 'UNKNOWN';
    }

    public static function normaliseCountry(?string $country): string
    {
        $country = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{3}$/', $country) ? $country : 'ZZZ';
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Per-content search performance for Intelligence → Content Performance.
 *
 * Built from the site-wide Search Console rows (reach_search_site_facts),
 * each page URL resolved to a content identity with the same URL index the
 * ingest uses (ContentIdentitySyncService::urlIndex). Resolving at read time
 * means an identity created after a day was ingested still gets that day.
 *
 *   list()   one row per content identity (clicks, impressions, CTR, position)
 *   detail() that identity's rows per day × page URL — each row is exactly
 *            what SearchExplorer shows for ?page=&date=, so it drills on to
 *            queries → devices → countries → the single Search Console row.
 *
 * The window defaults to the explorer's: 28 days ending on the freshest day.
 * Figures come from query-level rows, so they can sit below property totals
 * (Google withholds anonymised queries). No GA4 data is involved.
 */
class ContentSearchPerformanceService
{
    public function __construct(private ?BaseConnection $db = null, private int $tenantId = 1)
    {
    }

    public function isAvailable(): bool
    {
        try {
            return SchemaGuard::hasTable($this->db(), 'reach_search_site_facts')
                && SchemaGuard::hasTable($this->db(), 'reach_content_identities');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{from:string, to:string, latest:?string} */
    public function window(?string $from = null, ?string $to = null): array
    {
        $latest = $this->isAvailable() ? (new SearchSiteAnalyticsService($this->db()))->latestDate() : null;
        $to     = self::date($to) ?? $latest ?? date('Y-m-d');
        $from   = self::date($from) ?? date('Y-m-d', strtotime($to . ' -27 days'));

        return ['from' => $from, 'to' => $to, 'latest' => $latest];
    }

    /**
     * @return array{rows:list<array<string,mixed>>, totals:array<string,int>, unmapped:array<string,int>}
     */
    public function list(string $from, string $to, ?int $identityId = null): array
    {
        $empty = ['rows' => [], 'totals' => ['clicks' => 0, 'impressions' => 0], 'unmapped' => ['pages' => 0, 'clicks' => 0, 'impressions' => 0]];
        if (! $this->isAvailable()) {
            return $empty;
        }

        $pages = $this->db()->query(
            "SELECT page_url, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                    SUM(avg_position * impressions) AS pos_weight, COUNT(DISTINCT metric_date) AS days
             FROM reach_search_site_facts
             WHERE metric_date BETWEEN ? AND ? AND page_url <> ''
             GROUP BY page_url",
            [$from, $to]
        )->getResultArray();

        $index    = (new ContentIdentitySyncService($this->db()))->urlIndex($this->tenantId);
        $by       = [];
        $unmapped = $empty['unmapped'];
        foreach ($pages as $p) {
            $id = $index[ContentIdentitySyncService::normaliseUrl((string) $p['page_url'])] ?? null;
            if ($id === null) {
                $unmapped['pages']++;
                $unmapped['clicks']      += (int) $p['clicks'];
                $unmapped['impressions'] += (int) $p['impressions'];
                continue;
            }
            if ($identityId !== null && $id !== $identityId) {
                continue;
            }
            $by[$id] ??= ['clicks' => 0, 'impressions' => 0, 'pos_weight' => 0.0, 'days' => 0, 'urls' => []];
            $by[$id]['clicks']      += (int) $p['clicks'];
            $by[$id]['impressions'] += (int) $p['impressions'];
            $by[$id]['pos_weight']  += (float) $p['pos_weight'];
            $by[$id]['days']         = max($by[$id]['days'], (int) $p['days']);
            $by[$id]['urls'][]       = (string) $p['page_url'];
        }

        $labels = (new ContentIdentityLabels($this->db()))->forIds(array_keys($by));
        $rows   = [];
        $totals = ['clicks' => 0, 'impressions' => 0];
        foreach ($by as $id => $agg) {
            $label = $labels[$id] ?? [];
            sort($agg['urls']);
            $rows[] = [
                'content_identity_id' => $id,
                'content_type'        => $label['content_type'] ?? null,
                'title'               => $label['title'] ?? null,
                'content_item_id'     => $label['content_item_id'] ?? null,
                'url'                 => $label['canonical_url'] ?? ($agg['urls'][0] ?? null),
                'page_urls'           => $agg['urls'],
                'clicks'              => $agg['clicks'],
                'impressions'         => $agg['impressions'],
                'ctr'                 => $agg['impressions'] > 0 ? round($agg['clicks'] / $agg['impressions'], 6) : null,
                'avg_position'        => $agg['impressions'] > 0 ? round($agg['pos_weight'] / $agg['impressions'], 2) : null,
                'days'                => $agg['days'],
            ];
            $totals['clicks']      += $agg['clicks'];
            $totals['impressions'] += $agg['impressions'];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['clicks'], $b['impressions'], $a['content_identity_id']]
            <=> [$a['clicks'], $a['impressions'], $b['content_identity_id']]);

        return ['rows' => $rows, 'totals' => $totals, 'unmapped' => $unmapped];
    }

    /**
     * One identity's daily rows (day × page URL), newest first. Null when the
     * identity does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $identityId, string $from, string $to): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }
        $labels = (new ContentIdentityLabels($this->db()))->forIds([$identityId]);
        if (! isset($labels[$identityId])) {
            return null;
        }

        $summary = $this->list($from, $to, $identityId)['rows'][0] ?? null;
        $urls    = $summary['page_urls'] ?? [];
        $daily   = [];
        if ($urls !== []) {
            $marks = implode(',', array_fill(0, count($urls), '?'));
            $daily = $this->db()->query(
                "SELECT metric_date::text AS date, page_url, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                        CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position,
                        COUNT(*) AS rows_count
                 FROM reach_search_site_facts
                 WHERE metric_date BETWEEN ? AND ? AND page_url IN ({$marks})
                 GROUP BY metric_date, page_url
                 ORDER BY metric_date DESC, SUM(clicks) DESC, page_url",
                array_merge([$from, $to], $urls)
            )->getResultArray();
        }

        return [
            'content'  => $labels[$identityId],
            'summary'  => $summary,
            'daily'    => array_map(static function (array $r): array {
                $clicks      = (int) $r['clicks'];
                $impressions = (int) $r['impressions'];

                return [
                    'date'         => $r['date'],
                    'page_url'     => $r['page_url'],
                    'clicks'       => $clicks,
                    'impressions'  => $impressions,
                    'ctr'          => $impressions > 0 ? round($clicks / $impressions, 6) : null,
                    'avg_position' => $r['avg_position'] !== null ? round((float) $r['avg_position'], 2) : null,
                    'rows'         => (int) $r['rows_count'],
                ];
            }, $daily),
        ];
    }

    private static function date(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }
}

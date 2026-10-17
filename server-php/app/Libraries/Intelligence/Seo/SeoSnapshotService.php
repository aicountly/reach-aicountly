<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Seo;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Daily SEO snapshots for the SEO Command Centre.
 *
 * Rank tracking is honest: it aggregates ingested Search Console facts
 * (reach_search_metric_facts) per tracked keyword — never scraped SERPs.
 * When Search Console ingestion is off or has no data, snapshots simply
 * record zero rows rather than fabricating positions.
 */
class SeoSnapshotService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** Keyword statuses that are still being worked on (the CHECK allows open, planned, in_progress, done, archived). */
    public const ACTIVE_KEYWORD_STATUSES = ['open', 'planned', 'in_progress'];

    /**
     * Snapshot tracked keywords for one day of Search Console data.
     *
     * The default day is the freshest one actually ingested — Search Console
     * finalises on a SEARCH_CONSOLE_DATA_LAG_DAYS lag (default 3), and the
     * connector never ingests inside it. The old fixed `-2 days` therefore
     * always pointed at a day with no rows, and nothing was ever written.
     *
     * Facts come from the site-wide table when it has rows for that day (a
     * tracked keyword can rank via a product or pricing page, not only a
     * blog), otherwise from the blog-mapped facts.
     *
     * @return array{keywords_tracked:int, snapshots_written:int, date:?string, source:?string, reason?:string}
     */
    public function captureRankSnapshots(?string $forDate = null): array
    {
        $date = $forDate ?: $this->latestAvailableDate();

        $keywords = $this->db->table('reach_keyword_ideas')
            ->whereIn('status', self::ACTIVE_KEYWORD_STATUSES)
            ->get()->getResultArray();

        if ($date === null) {
            return [
                'keywords_tracked'  => count($keywords),
                'snapshots_written' => 0,
                'date'              => null,
                'source'            => null,
                'reason'            => 'No Search Console data has been ingested yet.',
            ];
        }

        $table = $this->factTableFor($date);

        $written = 0;
        foreach ($keywords as $keyword) {
            $term = trim((string) $keyword['keyword']);
            if ($term === '') {
                continue;
            }

            $fact = $this->db->query(
                "SELECT
                    COALESCE(SUM(clicks), 0)      AS clicks,
                    COALESCE(SUM(impressions), 0) AS impressions,
                    CASE WHEN SUM(impressions) > 0
                         THEN SUM(clicks)::numeric / SUM(impressions) END AS ctr,
                    CASE WHEN SUM(impressions) > 0
                         THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
                 FROM {$table}
                 WHERE metric_date = ? AND query ILIKE ?",
                [$date, '%' . $term . '%']
            )->getRowArray() ?: [];

            if ((int) ($fact['impressions'] ?? 0) === 0) {
                continue; // nothing to record — never invent a position
            }

            $bestPage = $this->db->query(
                "SELECT page_url FROM {$table}
                 WHERE metric_date = ? AND query ILIKE ?
                 ORDER BY clicks DESC, impressions DESC LIMIT 1",
                [$date, '%' . $term . '%']
            )->getRowArray()['page_url'] ?? null;

            $this->db->query(
                'INSERT INTO reach_seo_rank_snapshots
                    (keyword_id, keyword, metric_date, clicks, impressions, ctr, avg_position, best_page_url)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (keyword_id, metric_date) DO UPDATE SET
                    clicks = EXCLUDED.clicks,
                    impressions = EXCLUDED.impressions,
                    ctr = EXCLUDED.ctr,
                    avg_position = EXCLUDED.avg_position,
                    best_page_url = EXCLUDED.best_page_url',
                [
                    (int) $keyword['id'], $term, $date,
                    (int) $fact['clicks'], (int) $fact['impressions'],
                    $fact['ctr'], $fact['avg_position'], $bestPage,
                ]
            );
            $written++;
        }

        return [
            'keywords_tracked'  => count($keywords),
            'snapshots_written' => $written,
            'date'              => $date,
            'source'            => $table,
        ];
    }

    /**
     * The freshest ingested day, capped at today − SEARCH_CONSOLE_DATA_LAG_DAYS
     * so a partial day can never be snapshotted. Null when nothing is ingested.
     */
    public function latestAvailableDate(): ?string
    {
        $cap = date('Y-m-d', strtotime('-' . self::dataLagDays() . ' days'));

        foreach (['reach_search_site_facts', 'reach_search_metric_facts'] as $table) {
            if (! SchemaGuard::hasTable($this->db, $table)) {
                continue;
            }
            $row = $this->db->query(
                "SELECT MAX(metric_date)::text AS d FROM {$table} WHERE metric_date <= ?",
                [$cap]
            )->getRowArray();
            if (! empty($row['d'])) {
                return (string) $row['d'];
            }
        }

        return null;
    }

    public static function dataLagDays(): int
    {
        $value = getenv('SEARCH_CONSOLE_DATA_LAG_DAYS');
        if ($value === false || $value === '') {
            $value = $_ENV['SEARCH_CONSOLE_DATA_LAG_DAYS'] ?? '3';
        }

        return max(0, (int) $value);
    }

    private function factTableFor(string $date): string
    {
        if (SchemaGuard::hasTable($this->db, 'reach_search_site_facts')) {
            $has = $this->db->query('SELECT 1 FROM reach_search_site_facts WHERE metric_date = ? LIMIT 1', [$date])->getRowArray();
            if ($has) {
                return 'reach_search_site_facts';
            }
        }

        return 'reach_search_metric_facts';
    }

    /**
     * Free-tier backlink signal snapshot. External backlink counts require a
     * paid index (DataForSEO/Ahrefs) — when DATAFORSEO_LOGIN is unset the
     * provider records internal signals only and says so, instead of
     * pretending zero backlinks exist.
     *
     * @return array<string,mixed>
     */
    public function captureBacklinkSnapshot(): array
    {
        $provider = trim((string) ReachConfig::get('seo.dataforseo.login', '')) !== '' ? 'dataforseo' : 'internal_signals';
        $date     = date('Y-m-d');

        $internalLinks = 0;
        $publishedUrls = 0;
        try {
            $internalLinks = (int) $this->db->table('reach_content_internal_links')->countAllResults();
        } catch (\Throwable) {
        }
        try {
            $publishedUrls = (int) $this->db->table('reach_blog_publication_profiles p')
                ->join('reach_content_items i', 'i.id = p.content_item_id')
                ->whereIn('i.workflow_status', ['published', 'live'])
                ->countAllResults();
        } catch (\Throwable) {
        }

        $details = ['note' => 'internal signals only; external backlink index not configured'];
        $total   = null;
        $domains = null;

        if ($provider === 'dataforseo') {
            // DataForSEO is wired for SERP positions (SEO Tracker), not for
            // the Backlinks API. Recording an honest "not implemented" beats
            // silently pretending a backlink count was fetched.
            $details = ['note' => 'dataforseo credentials present (used by the SEO Tracker); backlink adapter not implemented'];
        }

        $this->db->query(
            'INSERT INTO reach_backlink_snapshots
                (provider, snapshot_date, total_backlinks, referring_domains, internal_links, published_urls, details_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (provider, snapshot_date) DO UPDATE SET
                total_backlinks = EXCLUDED.total_backlinks,
                referring_domains = EXCLUDED.referring_domains,
                internal_links = EXCLUDED.internal_links,
                published_urls = EXCLUDED.published_urls,
                details_json = EXCLUDED.details_json',
            [$provider, $date, $total, $domains, $internalLinks, $publishedUrls, json_encode($details)]
        );

        return [
            'provider'       => $provider,
            'snapshot_date'  => $date,
            'internal_links' => $internalLinks,
            'published_urls' => $publishedUrls,
        ];
    }
}

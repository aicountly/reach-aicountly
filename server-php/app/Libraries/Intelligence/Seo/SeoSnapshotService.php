<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Seo;

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

    /**
     * @return array{keywords_tracked:int, snapshots_written:int, date:string}
     */
    public function captureRankSnapshots(?string $forDate = null): array
    {
        // GSC data lags ~2 days; snapshot the freshest complete day.
        $date = $forDate ?: date('Y-m-d', strtotime('-2 days'));

        $keywords = $this->db->table('reach_keyword_ideas')
            ->whereIn('status', ['open', 'tracking', 'in_progress'])
            ->get()->getResultArray();

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
                 FROM reach_search_metric_facts
                 WHERE metric_date = ? AND query ILIKE ?",
                [$date, '%' . $term . '%']
            )->getRowArray() ?: [];

            if ((int) ($fact['impressions'] ?? 0) === 0) {
                continue; // nothing to record — never invent a position
            }

            $bestPage = $this->db->query(
                "SELECT page_url FROM reach_search_metric_facts
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

        return ['keywords_tracked' => count($keywords), 'snapshots_written' => $written, 'date' => $date];
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
        $provider = trim((string) env('DATAFORSEO_LOGIN', '')) !== '' ? 'dataforseo' : 'internal_signals';
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
            // Adapter seam: implemented when the account is provisioned.
            // Recording an honest "configured but not implemented" beats
            // silently pretending the integration ran.
            $details = ['note' => 'dataforseo credentials present; adapter pending implementation'];
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

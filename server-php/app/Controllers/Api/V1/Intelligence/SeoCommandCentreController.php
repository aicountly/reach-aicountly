<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Intelligence\Seo\SeoRecordsService;
use App\Libraries\Intelligence\Visibility\VisibilityRecordsService;

/**
 * SEO Command Centre aggregation endpoints. Every number here comes from a
 * real source (ingested GSC facts, IndexNow submissions, publication
 * profiles, visibility runs) — nothing is estimated or fabricated.
 */
class SeoCommandCentreController extends BaseApiController
{
    public function overview()
    {
        $db   = db_connect();
        $safe = static function (callable $fn, $fallback = null) {
            try {
                return $fn();
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $since = date('Y-m-d', strtotime('-28 days'));

        $search = $safe(static fn () => $db->query(
            "SELECT COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(impressions),0) AS impressions,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
             FROM reach_search_metric_facts WHERE metric_date >= ?",
            [$since]
        )->getRowArray(), ['clicks' => 0, 'impressions' => 0, 'avg_position' => null]);

        // Prefer the whole-property figures; the blog-mapped table only holds
        // rows a Reach post claims, which understates the site.
        $searchScope = 'blog';
        $site = $safe(static function () use ($since) {
            $svc = new \App\Libraries\Intelligence\SearchSiteAnalyticsService();
            if ($svc->latestDate() === null) {
                return null;
            }
            $t = $svc->totals($since, date('Y-m-d'));

            return ['clicks' => $t['clicks'], 'impressions' => $t['impressions'], 'avg_position' => $t['avg_position']];
        });
        if (is_array($site)) {
            $search      = $site;
            $searchScope = 'site';
        }

        $indexing = $safe(static fn () => $db->query(
            'SELECT indexing_status, COUNT(*) AS c FROM reach_blog_publication_profiles GROUP BY indexing_status'
        )->getResultArray(), []);

        $indexnow = $safe(static fn () => $db->query(
            'SELECT status, COUNT(*) AS c FROM reach_indexnow_submissions GROUP BY status'
        )->getResultArray(), []);

        $latestSitemap = $safe(static fn () => $db->table('reach_sitemap_snapshots')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray());

        // Runs have no created_at; count on the window the run list's
        // ?days=28 filter uses so the tile and its list agree.
        $visibility = $safe(static fn () => $db->query(
            "SELECT COUNT(*) AS runs FROM reach_ai_visibility_runs WHERE tenant_id = 1 AND COALESCE(completed_at, queued_at) >= ?",
            [VisibilityRecordsService::since(28)]
        )->getRowArray(), ['runs' => 0]);

        $backlinks = $safe(static fn () => $db->table('reach_backlink_snapshots')
            ->orderBy('snapshot_date', 'DESC')->limit(1)->get()->getRowArray());

        $topMovers = $safe(static fn () => $db->query(
            "SELECT keyword, metric_date, avg_position, clicks, impressions
             FROM reach_seo_rank_snapshots
             WHERE metric_date >= ?
             ORDER BY metric_date DESC, impressions DESC
             LIMIT 20",
            [date('Y-m-d', strtotime('-7 days'))]
        )->getResultArray(), []);

        return $this->ok([
            'search_28d'      => $search,
            'search_scope'    => $searchScope,
            'indexing_status' => $indexing,
            'indexnow_status' => $indexnow,
            'latest_sitemap'  => $latestSitemap,
            'visibility_28d'  => $visibility,
            'backlinks'       => $backlinks,
            'recent_ranks'    => $topMovers,
            'connections'     => [
                'search_console' => filter_var(env('SEARCH_CONSOLE_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
                'indexnow'       => filter_var(env('INDEXNOW_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
                'ga4'            => trim((string) env('GA4_PROPERTY_ID_MARKETING', '')) !== '',
            ],
        ]);
    }

    public function keywords()
    {
        $db = db_connect();

        $keywords = $db->table('reach_keyword_ideas')->orderBy('id', 'DESC')->limit(300)->get()->getResultArray();
        $latest   = [];
        try {
            foreach ($db->query(
                'SELECT DISTINCT ON (keyword_id) keyword_id, metric_date, clicks, impressions, avg_position, best_page_url
                 FROM reach_seo_rank_snapshots
                 ORDER BY keyword_id, metric_date DESC'
            )->getResultArray() as $row) {
                $latest[(int) $row['keyword_id']] = $row;
            }
        } catch (\Throwable) {
        }

        foreach ($keywords as &$keyword) {
            $keyword['latest_snapshot'] = $latest[(int) $keyword['id']] ?? null;
        }

        return $this->ok(['keywords' => $keywords]);
    }

    public function keywordHistory(int $id)
    {
        $rows = db_connect()->table('reach_seo_rank_snapshots')
            ->where('keyword_id', $id)
            ->orderBy('metric_date', 'ASC')
            ->limit(180)
            ->get()->getResultArray();

        return $this->ok(['history' => $rows]);
    }

    public function backlinks()
    {
        $rows = db_connect()->table('reach_backlink_snapshots')
            ->orderBy('snapshot_date', 'DESC')
            ->limit(90)
            ->get()->getResultArray();

        return $this->ok([
            'snapshots' => $rows,
            'provider_configured' => trim((string) env('DATAFORSEO_LOGIN', '')) !== '' ? 'dataforseo' : 'internal_signals',
        ]);
    }

    /**
     * GET seo/backlinks/{id} — one snapshot, its details_json as rows and the
     * records behind its counts (?kind=internal_links|published_urls, status,
     * target_type, q, page, per_page).
     */
    public function backlinkSnapshot(int $id)
    {
        $get    = $this->request->getGet() ?? [];
        $result = (new SeoRecordsService())->backlinkSnapshot($id, $get, (int) ($get['page'] ?? 1), (int) ($get['per_page'] ?? 50));

        return $result === null ? $this->notFound('Backlink snapshot not found.') : $this->ok($result);
    }

    /**
     * GET seo/indexnow/submissions — status (one or a comma list), url
     * (contains), content_identity_id, page / per_page (limit is the legacy
     * page size). `counts` are per status over the same url/content filter.
     */
    public function indexnowSubmissions()
    {
        $get     = $this->request->getGet() ?? [];
        $perPage = (int) ($get['per_page'] ?? $get['limit'] ?? 100);
        $result  = (new SeoRecordsService())->indexnowSubmissions($get, (int) ($get['page'] ?? 1), $perPage);

        return $this->ok([
            'submissions' => $result['rows'],
            'counts'      => $result['counts'],
            'meta'        => array_diff_key($result, ['rows' => true, 'counts' => true]),
        ]);
    }

    /** GET seo/indexnow/submissions/{id} — the submission and every attempt. */
    public function indexnowSubmission(int $id)
    {
        $row = (new SeoRecordsService())->indexnowSubmission($id);

        return $row === null ? $this->notFound('Submission not found.') : $this->ok($row);
    }

    /**
     * GET seo/indexing?status= — blog posts by indexing status, the rows the
     * overview's "Blog indexing status" counts group. q, page, per_page.
     */
    public function indexing()
    {
        $get    = $this->request->getGet() ?? [];
        $result = (new SeoRecordsService())->blogPosts(
            ['indexing_status' => $get['status'] ?? null, 'q' => $get['q'] ?? null],
            (int) ($get['page'] ?? 1),
            (int) ($get['per_page'] ?? 50),
        );

        return $this->ok([
            'posts'  => $result['rows'],
            'counts' => $result['counts'],
            'meta'   => array_diff_key($result, ['rows' => true, 'counts' => true]),
        ]);
    }

    /**
     * Rule-based suggestions from real GSC data: pages with high impressions
     * but poor CTR are title/meta candidates — these feed the roadmap's
     * IMPROVE_TITLE_META decisions rather than inventing advice.
     */
    public function suggestions()
    {
        $db   = db_connect();
        $rows = [];
        try {
            $rows = $db->query(
                "SELECT page_url,
                        SUM(impressions) AS impressions,
                        SUM(clicks) AS clicks,
                        CASE WHEN SUM(impressions) > 0 THEN SUM(clicks)::numeric / SUM(impressions) END AS ctr,
                        CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
                 FROM reach_search_metric_facts
                 WHERE metric_date >= ?
                 GROUP BY page_url
                 HAVING SUM(impressions) >= 100
                    AND (SUM(clicks)::numeric / NULLIF(SUM(impressions), 0)) < 0.02
                 ORDER BY SUM(impressions) DESC
                 LIMIT 50",
                [date('Y-m-d', strtotime('-28 days'))]
            )->getResultArray();
        } catch (\Throwable) {
        }

        $suggestions = array_map(static fn (array $row) => [
            'page_url'     => $row['page_url'],
            'impressions'  => (int) $row['impressions'],
            'clicks'       => (int) $row['clicks'],
            'ctr'          => $row['ctr'],
            'avg_position' => $row['avg_position'],
            'suggestion'   => 'High impressions but CTR under 2% — rework the title/meta description to match the dominant queries.',
        ], $rows);

        return $this->ok(['suggestions' => $suggestions]);
    }
}

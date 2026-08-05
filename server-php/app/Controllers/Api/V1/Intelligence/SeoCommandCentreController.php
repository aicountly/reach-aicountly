<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\Api\V1\BaseApiController;

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

        $indexing = $safe(static fn () => $db->query(
            'SELECT indexing_status, COUNT(*) AS c FROM reach_blog_publication_profiles GROUP BY indexing_status'
        )->getResultArray(), []);

        $indexnow = $safe(static fn () => $db->query(
            'SELECT status, COUNT(*) AS c FROM reach_indexnow_submissions GROUP BY status'
        )->getResultArray(), []);

        $latestSitemap = $safe(static fn () => $db->table('reach_sitemap_snapshots')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray());

        $visibility = $safe(static fn () => $db->query(
            "SELECT COUNT(*) AS runs FROM reach_ai_visibility_runs WHERE created_at >= ?",
            [date('Y-m-d H:i:s', strtotime('-28 days'))]
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

    public function indexnowSubmissions()
    {
        $builder = db_connect()->table('reach_indexnow_submissions')
            ->orderBy('id', 'DESC')
            ->limit(min(200, max(1, (int) ($this->request->getGet('limit') ?: 100))));

        $status = trim((string) $this->request->getGet('status'));
        if ($status !== '') {
            $builder->where('status', $status);
        }

        return $this->ok(['submissions' => $builder->get()->getResultArray()]);
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

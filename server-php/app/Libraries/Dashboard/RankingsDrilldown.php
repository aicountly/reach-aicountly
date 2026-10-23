<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\SearchSiteAnalyticsService;
use App\Libraries\Intelligence\Seo\SeoTrackerService;
use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The detail behind the command centre's Rankings card: every tracked keyword
 * with our position, the previous one and who else ranks, which keywords each
 * competitor holds, and which AI-visibility questions named whom.
 *
 * Served by `GET v1/dashboard/command-centre/rankings/keywords` and
 * `.../rankings/ai-answers`. Same rules as the card: stored rows only, and
 * "not checked" / "failed" are never shown as "not ranking".
 */
final class RankingsDrilldown
{
    public const VOLUME_NOTE = 'Search volume is not stored: the DataForSEO SERP check Reach runs returns positions only. The impressions column is Search Console data for that exact query (site-wide, last 28 days of data), not market volume.';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * The latest successful SERP check date and the one it is compared with:
     * the newest check at least six days older, else the oldest of the last
     * eight check dates. Shared with CommandCentreService::rankings() so the
     * card and its drill-down always talk about the same two days.
     *
     * @return array{latest: ?string, previous: ?string}
     */
    public static function comparisonDates(BaseConnection $db): array
    {
        if (! SchemaGuard::hasTable($db, 'reach_seo_serp_checks')) {
            return ['latest' => null, 'previous' => null];
        }
        $dates = array_column($db->query(
            "SELECT DISTINCT check_date::text AS d FROM reach_seo_serp_checks WHERE status = 'ok' ORDER BY d DESC LIMIT 8"
        )->getResultArray(), 'd');
        if ($dates === []) {
            return ['latest' => null, 'previous' => null];
        }
        $latest = $dates[0];
        $prev   = null;
        foreach (array_slice($dates, 1) as $d) {
            $prev = $d;
            if ((strtotime($latest) - strtotime($d)) >= 6 * 86400) {
                break;
            }
        }

        return ['latest' => $latest, 'previous' => $prev];
    }

    /** @return array<string, mixed> */
    public function keywords(): array
    {
        $tracker = new SeoTrackerService($this->db);
        $status  = $tracker->status();
        $base    = [
            'tracker_status' => ['status' => $status['status'], 'detail' => $status['detail']],
            'depth'          => $status['depth'],
            'our_domain'     => $status['our_domain'],
            'volume_note'    => self::VOLUME_NOTE,
        ];
        if ($status['status'] === 'not_migrated') {
            return $base + ['available' => false, 'note' => $status['detail'], 'keywords' => [], 'competitors' => []];
        }

        ['latest' => $latest, 'previous' => $prev] = self::comparisonDates($this->db);

        $keywords = $this->db->query(
            'SELECT id, keyword, product, priority, enabled FROM reach_seo_tracked_keywords ORDER BY enabled DESC, keyword'
        )->getResultArray();
        if ($keywords === []) {
            return $base + ['available' => false, 'note' => 'No keywords are tracked yet. Add them under SEO & Search Console → Tracked keywords.',
                'latest_date' => $latest, 'compared_with' => $prev, 'keywords' => [], 'competitors' => []];
        }

        $byDate = static function (BaseConnection $db, ?string $date): array {
            $out = [];
            if ($date !== null) {
                foreach ($db->query('SELECT keyword_id, status, our_rank, our_url, error FROM reach_seo_serp_checks WHERE check_date = ?', [$date])->getResultArray() as $c) {
                    $out[(int) $c['keyword_id']] = $c;
                }
            }

            return $out;
        };
        $cur  = $byDate($this->db, $latest);
        $was  = $byDate($this->db, $prev);
        $last = [];
        foreach ($this->db->query(
            "SELECT keyword_id, MAX(checked_at) AS last_checked_at, MAX(check_date) FILTER (WHERE status = 'ok')::text AS last_ok_date
               FROM reach_seo_serp_checks GROUP BY keyword_id"
        )->getResultArray() as $r) {
            $last[(int) $r['keyword_id']] = $r;
        }

        // Competitor positions on the latest date (best per keyword × competitor).
        $compRows = [];
        $compMeta = [];
        if ($latest !== null && SchemaGuard::hasTable($this->db, 'reach_competitors')) {
            foreach ($this->db->query(
                "SELECT DISTINCT ON (r.keyword_id, r.competitor_id) r.keyword_id, r.competitor_id, r.rank_absolute, r.url, c.name, c.website_domain
                   FROM reach_seo_serp_rankings r JOIN reach_competitors c ON c.id = r.competitor_id
                  WHERE r.check_date = ? AND r.competitor_id IS NOT NULL
                  ORDER BY r.keyword_id, r.competitor_id, r.rank_absolute",
                [$latest]
            )->getResultArray() as $r) {
                $cid = (int) $r['competitor_id'];
                $compMeta[$cid] = ['id' => $cid, 'name' => (string) $r['name'], 'domain' => (string) $r['website_domain']];
                $compRows[(int) $r['keyword_id']][] = ['competitor_id' => $cid, 'name' => (string) $r['name'], 'domain' => (string) $r['website_domain'],
                    'rank' => (int) $r['rank_absolute'], 'url' => $r['url']];
            }
        }

        $gsc   = $this->searchConsole(array_column($keywords, 'keyword'));
        $depth = (int) $status['depth'];
        $rows  = [];
        $placements = [];
        foreach ($keywords as $k) {
            $id    = (int) $k['id'];
            $c     = $cur[$id] ?? null;
            $p     = $was[$id] ?? null;
            $state = $c === null ? 'not_checked' : (string) $c['status'];
            $ok    = $state === 'ok';
            $our   = $ok && $c['our_rank'] !== null ? (int) $c['our_rank'] : null;
            $pOk   = $p !== null && $p['status'] === 'ok';
            $pRank = $pOk && $p['our_rank'] !== null ? (int) $p['our_rank'] : null;
            // Only a keyword checked on both days has a change. Positive = moved
            // up; entering/leaving the checked depth counts as depth + 1.
            $change = null;
            if ($ok && $pOk) {
                $change = ($pRank ?? $depth + 1) - ($our ?? $depth + 1);
            }

            $comps = $ok ? ($compRows[$id] ?? []) : [];
            usort($comps, static fn ($a, $b) => $a['rank'] <=> $b['rank']);
            foreach ($comps as $cr) {
                $placements[$cr['competitor_id']][] = ['keyword_id' => $id, 'keyword' => $k['keyword'], 'product' => $k['product'],
                    'rank' => $cr['rank'], 'url' => $cr['url'], 'our_rank' => $our];
            }
            $l = $last[$id] ?? [];

            $rows[] = [
                'id'               => $id,
                'keyword'          => $k['keyword'],
                'product'          => $k['product'],
                'priority'         => $k['priority'],
                'enabled'          => in_array($k['enabled'], [true, 't', 'true', '1', 1], true),
                'status'           => $state,
                'error'            => $state === 'failed' ? mb_substr((string) $c['error'], 0, 240) : null,
                'our_rank'         => $our,
                'our_url'          => $ok ? $c['our_url'] : null,
                'previous_rank'    => $pRank,
                'previous_checked' => $pOk,
                'change'           => $change,
                'last_checked_at'  => isset($l['last_checked_at']) ? self::ist((string) $l['last_checked_at']) : null,
                'last_ok_date'     => $l['last_ok_date'] ?? null,
                'competitors'      => array_map(static fn ($cr) => ['name' => $cr['name'], 'domain' => $cr['domain'], 'rank' => $cr['rank'], 'url' => $cr['url']], $comps),
                'best_competitor'  => $comps !== [] ? ['name' => $comps[0]['name'], 'domain' => $comps[0]['domain'], 'rank' => $comps[0]['rank'], 'url' => $comps[0]['url']] : null,
                'search_volume'    => null,
                'search_console'   => $gsc['rows'][mb_strtolower(trim((string) $k['keyword']))] ?? null,
            ];
        }
        // Ours first by position, then unranked, then not checked.
        $order = static fn (array $r): array => [$r['status'] === 'ok' ? 0 : 1, $r['our_rank'] ?? PHP_INT_MAX, mb_strtolower((string) $r['keyword'])];
        usort($rows, static fn ($a, $b) => $order($a) <=> $order($b));

        $competitors = [];
        foreach ($compMeta as $cid => $m) {
            $list = $placements[$cid] ?? [];
            usort($list, static fn ($a, $b) => $a['rank'] <=> $b['rank']);
            $ranks = array_column($list, 'rank');
            $competitors[] = $m + [
                'keywords' => count($list),
                'top10'    => count(array_filter($ranks, static fn (int $r): bool => $r <= 10)),
                'avg_rank' => $ranks !== [] ? round(array_sum($ranks) / count($ranks), 1) : null,
                'placements' => $list,
            ];
        }
        usort($competitors, static fn ($a, $b) => [$b['top10'], $a['avg_rank'] ?? PHP_FLOAT_MAX] <=> [$a['top10'], $b['avg_rank'] ?? PHP_FLOAT_MAX]);

        $checked = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'ok'));
        $ranked  = array_values(array_filter($checked, static fn ($r) => $r['our_rank'] !== null));

        return $base + [
            'available'     => $latest !== null,
            'note'          => $latest === null ? 'The SEO Tracker has not recorded a successful SERP check yet.' : null,
            'latest_date'   => $latest,
            'compared_with' => $prev,
            'summary'       => [
                'tracked'     => count($rows),
                'checked'     => count($checked),
                'ranked'      => count($ranked),
                'top10'       => count(array_filter($ranked, static fn ($r) => $r['our_rank'] <= 10)),
                'failed'      => count(array_filter($rows, static fn ($r) => $r['status'] === 'failed')),
                'not_checked' => count(array_filter($rows, static fn ($r) => $r['status'] === 'not_checked')),
                'avg_rank'    => $ranked !== [] ? round(array_sum(array_column($ranked, 'our_rank')) / count($ranked), 1) : null,
            ],
            'search_console_window' => $gsc['window'],
            'keywords'      => $rows,
            'competitors'   => $competitors,
        ];
    }

    /** @return array<string, mixed> */
    public function aiAnswers(int $days = 28): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_ai_visibility_runs')) {
            return ['available' => false, 'note' => 'AI-visibility tables are missing. Run: php spark migrate', 'prompts' => []];
        }
        $d = (new AiVisibilityService($this->db))->byPrompt($days);
        $t = $d['totals'];

        return $d + [
            'available'    => $t['answers'] > 0 || $t['failed'] > 0,
            'measured'     => $t['counted'] > 0,
            'mention_rate' => $t['counted'] > 0 ? round($t['brand_mentions'] / $t['counted'], 4) : null,
            'note'         => $t['answers'] === 0 && $t['failed'] === 0 ? 'No AI-visibility answers recorded in this period.' : null,
        ];
    }

    /**
     * Search Console impressions/clicks for each keyword as an exact query.
     *
     * @param list<string> $keywords
     * @return array{window: ?array{from:string, to:string}, rows: array<string, array{impressions:int, clicks:int, avg_position:?float}>}
     */
    private function searchConsole(array $keywords): array
    {
        $site   = new SearchSiteAnalyticsService($this->db);
        $latest = $site->latestDate();
        if ($latest === null || $keywords === []) {
            return ['window' => null, 'rows' => []];
        }
        $from  = (new \DateTimeImmutable($latest))->modify('-27 days')->format('Y-m-d');
        $lower = array_values(array_unique(array_map(static fn ($k) => mb_strtolower(trim((string) $k)), $keywords)));
        $marks = implode(',', array_fill(0, count($lower), '?'));
        $rows  = [];
        foreach ($this->db->query(
            "SELECT LOWER(query) AS q, SUM(impressions) AS impressions, SUM(clicks) AS clicks,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS avg_position
               FROM reach_search_site_facts
              WHERE metric_date BETWEEN ? AND ? AND LOWER(query) IN ({$marks})
              GROUP BY LOWER(query)",
            array_merge([$from, $latest], $lower)
        )->getResultArray() as $r) {
            $rows[(string) $r['q']] = [
                'impressions'  => (int) $r['impressions'],
                'clicks'       => (int) $r['clicks'],
                'avg_position' => $r['avg_position'] !== null ? round((float) $r['avg_position'], 1) : null,
            ];
        }

        return ['window' => ['from' => $from, 'to' => $latest], 'rows' => $rows];
    }

    private static function ist(string $ts): string
    {
        return (new \DateTimeImmutable($ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('c');
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use App\Libraries\Blog\BlogMixService;
use App\Libraries\Blog\BlogTaxonomy;
use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Plan vs achieved for a past range of IST days.
 *
 * Per metric:
 *   target       Σ of each day's plan-of-record target (PlanStore::dayPlans);
 *                days without a stored plan, and 'baseline' items, add nothing
 *   achieved     Σ actuals on the planned days that have data
 *   achievement  achieved / target × 100   (met ≥ 100, behind ≥ 80, lagging < 80)
 *   streak       consecutive days, ending on the range's last day, that were
 *                all lagging (< 80 %) or all met (≥ 100 %)
 *   causes       RootCauseClassifier on LagSignals, for lagging metrics
 *
 * Top lags: lagging outputs first, then outcomes, by lowest achievement.
 */
class PerformanceService
{
    public const MET     = 100;
    public const LAGGING = 80;

    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private ?ActualsSnapshotService $actuals = null,
        private ?LagSignals $signals = null,
    ) {
        $this->db      = $db ?? Database::connect();
        $this->actuals ??= new ActualsSnapshotService($this->db);
        $this->signals ??= new LagSignals($this->db);
    }

    /** @return array<string, mixed> */
    public function performance(string $rangeKey, ?\DateTimeImmutable $now = null): array
    {
        $r = PlanPeriods::range($rangeKey, $now);

        return $this->compute($r['from'], $r['to']) + ['range' => $r];
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(string $from, string $to, bool $withSignals = true): array
    {
        $store   = new PlanStore($this->db);
        $plans   = $store->dayPlans($from, $to);
        $actuals = $this->actuals->read($from, $to);
        $dates   = PlanPeriods::dates($from, $to);

        $rows = [];
        foreach (PlanMetrics::all() as $metric => $meta) {
            $rows[$metric] = self::metricRow($metric, $meta, $dates, $plans, $actuals[$metric] ?? []);
        }

        $laggingOutputs = array_keys(array_filter($rows, static fn ($r) => $r['status'] === 'lagging' && $r['kind'] === 'output'));
        $signals        = null;
        if ($withSignals && array_filter($rows, static fn ($r) => $r['status'] === 'lagging') !== []) {
            $signals = $this->signals->gather($from, $to);
        }
        foreach ($rows as $metric => $row) {
            if ($row['status'] === 'lagging' && $signals !== null) {
                $rows[$metric]['causes'] = RootCauseClassifier::classify($metric, $row, $signals, array_values(array_diff($laggingOutputs, [$metric])));
            }
        }

        $topLags = self::topLags($rows);

        return [
            'from'            => $from,
            'to'              => $to,
            'metrics'         => array_values($rows),
            'top_lags'        => $topLags,
            'recommendations' => self::recommendations($rows, $signals),
            'coverage'        => [
                'days'              => count($dates),
                'days_with_plan'    => count($plans),
                // Which days those are, so the tile can list them.
                'plan_dates'        => array_keys($plans),
                'first_plan_date'   => $store->firstPlanDate(),
                'note'              => count($plans) < count($dates)
                    ? sprintf('%d of %d day(s) in this range have no recorded plan (planning starts the day the planner first runs); those days count towards neither target nor achievement.', count($dates) - count($plans), count($dates))
                    : null,
            ],
            'signals_window'  => $signals['window'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>                               $meta
     * @param list<string>                                       $dates
     * @param array<string, array<string, array<string, mixed>>> $plans
     * @param array<string, float|null>                          $actual
     * @return array<string, mixed>
     */
    public static function metricRow(string $metric, array $meta, array $dates, array $plans, array $actual): array
    {
        $target = 0.0;
        $base   = 0.0;
        $got    = 0.0;
        $planDays = 0;
        $noData   = 0;
        $baselineDays = 0;
        $series = [];
        foreach ($dates as $d) {
            $item = $plans[$d][$metric] ?? null;
            $a    = $actual[$d] ?? null;
            $t    = ($item !== null && $item['status'] === 'target') ? (float) $item['target_value'] : null;
            if ($item !== null && $item['status'] === 'baseline') {
                $baselineDays++;
            }
            if ($t !== null) {
                $planDays++;
                if ($a === null) {
                    $noData++;
                } else {
                    $target += $t;
                    $base   += (float) ($item['base_target'] ?? $t);
                    $got    += $a;
                }
            }
            $series[] = ['date' => $d, 'target' => $t, 'achieved' => $a, 'adjusted' => ($item['source'] ?? '') === 'self_tune'];
        }

        $known       = array_filter(array_column($series, 'achieved'), static fn ($v) => $v !== null);
        $achievedAll = $known === [] ? null : array_sum($known);
        $pct         = $target > 0 ? (int) round($got / $target * 100) : null;

        $status = match (true) {
            $planDays === 0 && $baselineDays > 0 => 'baseline',
            $planDays === 0                     => 'no_plan',
            $planDays === $noData               => 'no_data',
            $target <= 0                        => 'nothing_planned',
            $pct >= self::MET                   => 'met',
            $pct >= self::LAGGING               => 'behind',
            default                             => 'lagging',
        };

        // Streaks over the planned days with data, newest first.
        $lagStreak = 0;
        $hitStreak = 0;
        $evaluated = array_reverse(array_values(array_filter($series, static fn ($s) => $s['target'] !== null && $s['target'] > 0 && $s['achieved'] !== null)));
        foreach ($evaluated as $s) {
            if ($s['achieved'] / $s['target'] * 100 < self::LAGGING) {
                $lagStreak++;
            } else {
                break;
            }
        }
        foreach ($evaluated as $s) {
            if ($s['achieved'] >= $s['target']) {
                $hitStreak++;
            } else {
                break;
            }
        }

        return [
            'metric_key'        => $metric,
            'label'             => $meta['label'],
            'group'             => $meta['group'],
            'kind'              => $meta['kind'],
            'unit'              => $meta['unit'],
            'target'            => $planDays > 0 ? round($target, 2) : null,
            'base_target'       => $planDays > 0 ? round($base, 2) : null,
            'achieved'          => $planDays > 0 ? round($got, 2) : ($achievedAll === null ? null : round($achievedAll, 2)),
            'achievement_pct'   => $pct,
            'gap'               => $planDays > 0 ? round($target - $got, 2) : null,
            'status'            => $status,
            'days_planned'      => $planDays,
            'days_without_data' => $noData,
            'streak'            => $lagStreak > 0 ? ['type' => 'lag', 'days' => $lagStreak] : ($hitStreak > 0 ? ['type' => 'hit', 'days' => $hitStreak] : null),
            'series'            => $series,
            'causes'            => [],
        ];
    }

    /** @param array<string, array<string, mixed>> $rows @return list<array<string, mixed>> */
    public static function topLags(array $rows, int $n = 3): array
    {
        $lagging = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'lagging'
            && ! (count($r['causes']) === 1 && $r['causes'][0]['code'] === 'data_lag')));
        usort($lagging, static fn ($a, $b) => [$a['kind'] === 'output' ? 0 : 1, $a['achievement_pct'], -$a['gap']]
            <=> [$b['kind'] === 'output' ? 0 : 1, $b['achievement_pct'], -$b['gap']]);

        return array_map(static fn ($r) => [
            'metric_key'      => $r['metric_key'],
            'label'           => $r['label'],
            'target'          => $r['target'],
            'achieved'        => $r['achieved'],
            'achievement_pct' => $r['achievement_pct'],
            'streak'          => $r['streak'],
            'cause'           => $r['causes'][0] ?? null,
            'causes'          => $r['causes'],
        ], array_slice($lagging, 0, $n));
    }

    /**
     * Things only the owner can change, de-duplicated by fix, plus volume
     * suggestions where the cap — not the pipeline — is the limit.
     *
     * @param array<string, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function recommendations(array $rows, ?array $signals): array
    {
        $out = [];
        foreach ($rows as $metric => $row) {
            foreach ($row['causes'] as $c) {
                if (empty($c['owner_action']) || empty($c['fix'])) {
                    continue;
                }
                $k = (string) $c['fix'];
                $out[$k] ??= ['action' => $c['fix'], 'why' => $c['label'], 'metrics' => [], 'severity' => $c['weight'] >= 80 ? 'high' : 'medium'];
                $out[$k]['metrics'][] = $row['label'];
            }
        }
        // Volume: a routine that hit its daily limit most days and was mostly approved.
        foreach (['blog' => 'content.blog.published', 'knowledge_base' => 'content.knowledge_base.published'] as $type => $metric) {
            $r    = $signals['routine'][$type] ?? null;
            $vet  = $signals['vetting'][$type] ?? null;
            $days = (int) ($signals['window']['days'] ?? 0);
            if ($r === null || $days < 7 || ($rows[$metric]['status'] ?? '') !== 'met') {
                continue;
            }
            $approval = ($vet['total'] ?? 0) > 0 ? ($vet['approve'] ?? 0) / $vet['total'] : 0;
            if ($r['quota_days'] >= (int) ceil($days * 0.7) && $approval >= 0.8) {
                $out["raise-{$type}"] = [
                    'action'   => sprintf('Consider raising automation.%s.daily_limit (now %d): the limit was reached on %d of %d days and Gemini approved %d%%. Raise it only once Search Console shows the current volume being indexed (docs/automation/CLAUDE_ROUTINES.md → Volume ramp).',
                        $type, $r['daily_limit'], $r['quota_days'], $days, (int) round($approval * 100)),
                    'why'      => 'The daily cap, not the pipeline, is limiting volume',
                    'metrics'  => [PlanMetrics::label($metric)],
                    'severity' => 'low',
                ];
            }
        }
        $list = array_values($out);
        usort($list, static fn ($a, $b) => ['high' => 0, 'medium' => 1, 'low' => 2][$a['severity']] <=> ['high' => 0, 'medium' => 1, 'low' => 2][$b['severity']]);

        return $list;
    }

    /**
     * Which blog categories / KB products to lean towards, from the last 28
     * days: Gemini approval rate and search clicks on the published pages
     * per canonical blog category (BlogTaxonomy — the profile's category,
     * old labels folded in), the app-led vs topic mix against the owner's
     * 50/50 target (with about one app-led post in five about a Beta app),
     * and the gap to each product's cadence (KB).
     *
     * @return array<string, mixed>
     */
    public function topicMix(string $to): array
    {
        $from  = PlanPeriods::addDays($to, -27);
        $start = PlanPeriods::istMidnightUtc($from);
        $end   = PlanPeriods::istMidnightUtc(PlanPeriods::addDays($to, 1));
        $out   = ['window' => ['from' => $from, 'to' => $to], 'blog' => ['prefer' => [], 'avoid' => [], 'categories' => [], 'mix' => null, 'steer' => null], 'knowledge_base' => ['prefer' => [], 'products' => []]];
        if (! SchemaGuard::hasTable($this->db, 'reach_routine_submissions')) {
            return $out;
        }

        // The category of record is the publication profile's (canonicalised:
        // rows filed under old labels count with the new slug); the
        // submission's copy covers a profile that is gone.
        $hasProfiles = SchemaGuard::hasTable($this->db, 'reach_blog_publication_profiles');
        $catSql      = $hasProfiles ? "COALESCE(NULLIF(p.category, ''), s.submission_json->>'category')" : "s.submission_json->>'category'";
        $profileJoin = $hasProfiles ? 'LEFT JOIN reach_blog_publication_profiles p ON p.content_item_id = s.content_item_id' : '';
        $canon       = static fn ($raw): string => BlogTaxonomy::canonical($raw !== null ? (string) $raw : null) ?? 'uncategorised';

        $clicks = [];
        if (SchemaGuard::hasTable($this->db, 'reach_search_site_facts')) {
            foreach ($this->db->query(
                "SELECT {$catSql} AS cat, SUM(f.clicks) AS clicks
                 FROM reach_routine_submissions s
                 JOIN reach_content_items i ON i.id = s.content_item_id
                 {$profileJoin}
                 JOIN reach_search_site_facts f ON f.page_url LIKE '%/' || i.slug || '%'
                 WHERE s.content_type = 'blog' AND s.status = 'live' AND i.slug IS NOT NULL AND i.slug <> ''
                   AND f.metric_date BETWEEN ? AND ?
                 GROUP BY 1",
                [$from, $to]
            )->getResultArray() as $r) {
                $c = $canon($r['cat']);
                $clicks[$c] = ($clicks[$c] ?? 0) + (int) $r['clicks'];
            }
        }
        $agg = [];
        foreach ($this->db->query(
            "SELECT {$catSql} AS cat,
                    COUNT(*) AS submitted, COUNT(*) FILTER (WHERE s.status = 'rejected') AS rejected, COUNT(*) FILTER (WHERE s.status = 'live') AS live
             FROM reach_routine_submissions s {$profileJoin}
             WHERE s.content_type = 'blog' AND s.created_at >= ? AND s.created_at < ? GROUP BY 1",
            [$start, $end]
        )->getResultArray() as $r) {
            $c = $canon($r['cat']);
            $agg[$c] ??= ['submitted' => 0, 'rejected' => 0, 'live' => 0];
            $agg[$c]['submitted'] += (int) $r['submitted'];
            $agg[$c]['rejected']  += (int) $r['rejected'];
            $agg[$c]['live']      += (int) $r['live'];
        }
        $cats = [];
        foreach ($agg as $c => $a) {
            $sub  = $a['submitted'];
            $live = $a['live'];
            $cats[] = [
                'category'        => $c,
                'label'           => BlogTaxonomy::label($c) ?? 'Uncategorised',
                'group'           => BlogTaxonomy::group($c),
                'submitted'       => $sub,
                'rejected'        => $a['rejected'],
                'live'            => $live,
                'approval_rate'   => $sub > 0 ? round(($sub - $a['rejected']) / $sub, 2) : null,
                'clicks'          => $clicks[$c] ?? null,
                'clicks_per_post' => $live > 0 && isset($clicks[$c]) ? round($clicks[$c] / $live, 1) : null,
            ];
        }
        usort($cats, static fn ($a, $b) => [-($a['clicks_per_post'] ?? -1), -($a['approval_rate'] ?? 0), $a['category']] <=> [-($b['clicks_per_post'] ?? -1), -($b['approval_rate'] ?? 0), $b['category']]);
        $out['blog']['categories'] = $cats;
        foreach ($cats as $c) {
            if ($c['category'] === 'uncategorised') {
                continue; // not something a routine can choose
            }
            if ($c['submitted'] >= 3 && $c['approval_rate'] !== null && $c['approval_rate'] < 0.5) {
                $out['blog']['avoid'][] = $c['category'];
            } elseif (($c['approval_rate'] ?? 0) >= 0.7 && count($out['blog']['prefer']) < 3) {
                $out['blog']['prefer'][] = $c['category'];
            }
        }

        // The owner's mix over the same window, and the steer that follows.
        try {
            $mix = BlogTaxonomy::mix((new BlogMixService($this->db))->posts($start, $end));
            $out['blog']['mix']   = array_diff_key($mix, ['next' => true]);
            $out['blog']['steer'] = $mix['next'] + ['why' => BlogMixService::why($mix, 'Last 28 days')];
        } catch (\Throwable $e) {
            log_message('warning', '[PerformanceService] blog mix unavailable: ' . $e->getMessage());
        }

        if (SchemaGuard::hasTable($this->db, 'reach_kb_product_cadence') && SchemaGuard::hasTable($this->db, 'reach_kb_publication_profiles')) {
            $since = PlanPeriods::istMidnightUtc(PlanPeriods::addDays($to, -6));
            $rows  = $this->db->query(
                "SELECT c.product_slug, c.daily_quota, COALESCE(x.n, 0) AS published
                 FROM reach_kb_product_cadence c
                 LEFT JOIN (
                    SELECT pr.slug, COUNT(*) AS n FROM reach_routine_submissions s
                    JOIN reach_kb_publication_profiles p ON p.content_item_id = s.content_item_id
                    JOIN reach_products pr ON pr.id = p.product_id
                    WHERE s.content_type = 'knowledge_base' AND s.status = 'live' AND s.created_at >= ? AND s.created_at < ?
                    GROUP BY pr.slug) x ON x.slug = c.product_slug
                 WHERE c.enabled = TRUE",
                [$since, $end]
            )->getResultArray();
            $products = array_map(static fn ($r) => [
                'product_slug' => (string) $r['product_slug'],
                'weekly_cadence' => (int) $r['daily_quota'] * 7,
                'published_7d' => (int) $r['published'],
                'gap' => (int) $r['daily_quota'] * 7 - (int) $r['published'],
            ], $rows);
            usort($products, static fn ($a, $b) => $b['gap'] <=> $a['gap']);
            $out['knowledge_base']['products'] = $products;
            $out['knowledge_base']['prefer']   = array_values(array_map(static fn ($p) => $p['product_slug'], array_slice(array_filter($products, static fn ($p) => $p['gap'] > 0), 0, 3)));
        }

        return $out;
    }

    /**
     * The daily review (stored per review date): 7 days ending $date, the
     * day itself, lessons and recommendations, the topic mix. $week may be
     * passed in when the caller already computed it.
     *
     * @return array<string, mixed>
     */
    public function review(string $date, ?array $week = null): array
    {
        $week ??= $this->compute(PlanPeriods::addDays($date, -6), $date);
        $day  = $this->compute($date, $date, false);

        return [
            'review_date'     => $date,
            'generated_at'    => gmdate('c'),
            'day'             => array_map(static fn ($m) => array_intersect_key($m, array_flip(['metric_key', 'label', 'kind', 'unit', 'target', 'achieved', 'achievement_pct', 'status'])), $day['metrics']),
            'week'            => array_map(static fn ($m) => array_diff_key($m, ['series' => true]), $week['metrics']),
            'top_lags'        => $week['top_lags'],
            'recommendations' => $week['recommendations'],
            'coverage'        => $week['coverage'],
            'topic_mix'       => $this->topicMix($date),
        ];
    }

    /** @param array<string, mixed> $summary */
    public function storeReview(array $summary): void
    {
        $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->db->query(
            'INSERT INTO reach_bot_performance_reviews (review_date, summary_json, created_at, updated_at) VALUES (?, ?::jsonb, NOW(), NOW())
             ON CONFLICT (review_date) DO UPDATE SET summary_json = EXCLUDED.summary_json, updated_at = NOW()',
            [$summary['review_date'], $json]
        );
    }

    /** @return array<string, mixed>|null the newest stored review */
    public function latestReview(): ?array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_bot_performance_reviews')) {
            return null;
        }
        $row = $this->db->query('SELECT summary_json::text AS s FROM reach_bot_performance_reviews ORDER BY review_date DESC LIMIT 1')->getRowArray();

        return $row ? (json_decode((string) $row['s'], true) ?: null) : null;
    }
}

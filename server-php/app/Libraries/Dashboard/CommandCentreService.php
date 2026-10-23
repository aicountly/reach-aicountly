<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\SearchSiteAnalyticsService;
use App\Libraries\Jobs\JobQueueInsights;
use App\Libraries\Settings\ReachConfig;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * The owner's "AI bot command centre": what the bot did, what it achieved,
 * where Aicountly ranks, where the numbers are heading, what to do next, and
 * whether the machinery is healthy — one aggregated payload for
 * `GET v1/dashboard/command-centre`.
 *
 * Rules:
 *  - Real data only. Every figure is a count or sum of stored rows; where
 *    there is nothing yet the section says so (`status: empty` plus a note)
 *    instead of estimating. Forecasts are hidden below Forecast::MIN_POINTS
 *    days and flagged when volume is low.
 *  - Search figures are site-wide (every Search Console row), with the share
 *    Reach-published pages earned shown apart — on a young site almost all
 *    traffic lands on pages Reach did not write.
 *  - Each section is built, cached and failed independently: one broken
 *    source yields `status: error` for its card, never a 500 for the page.
 */
final class CommandCentreService
{
    public const SECTIONS = ['health', 'activity', 'impact', 'rankings', 'projections', 'recommendations'];

    /** Seconds each section may be served from cache. */
    private const TTL = [
        'health'          => 60,
        'activity'        => 60,
        'impact'          => 600,
        'rankings'        => 600,
        'projections'     => 900,
        'recommendations' => 120,
    ];

    /** Last-live-check summary written by the settings live check / reach:integrations-check. */
    public const LIVE_CHECK_SETTING = 'live_checks.last';

    private BaseConnection $db;
    private \DateTimeImmutable $now;

    /** @var array<string, array<string, mixed>> built sections, so recommendations reuse them */
    private array $built = [];

    public function __construct(?BaseConnection $db = null, ?\DateTimeImmutable $now = null)
    {
        $this->db  = $db ?? Database::connect();
        $this->now = $now ?? new \DateTimeImmutable('now');
    }

    /**
     * @param list<string> $only sections to build (default all)
     * @return array<string, mixed>
     */
    public function build(array $only = [], bool $fresh = false): array
    {
        $sections = $only === [] ? self::SECTIONS : array_values(array_intersect(self::SECTIONS, $only));
        $out      = ['generated_at' => $this->now->format('c')];
        foreach ($sections as $section) {
            $out[$section] = $this->section($section, $fresh);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function section(string $name, bool $fresh): array
    {
        $key   = 'reach_cc_' . $name;
        $cache = null;
        try {
            $cache = Services::cache();
            if (! $fresh && is_array($hit = $cache->get($key))) {
                return $this->built[$name] = $hit + ['cached' => true];
            }
        } catch (\Throwable) {
            $cache = null;
        }

        try {
            $data = $this->{$name}();
            $data += ['status' => 'ok'];
        } catch (\Throwable $e) {
            log_message('error', "CommandCentre {$name}: " . $e->getMessage());
            $data = ['status' => 'error', 'message' => 'This section could not be loaded: ' . mb_substr($e->getMessage(), 0, 200)];
        }
        $data['built_at'] = $this->now->format('c');

        if ($cache !== null && $data['status'] !== 'error') {
            try {
                $cache->save($key, $data, self::TTL[$name]);
            } catch (\Throwable) {
            }
        }

        return $this->built[$name] = $data + ['cached' => false];
    }

    // ───────────────────────────────────────────────────────────── health

    /** @return array<string, mixed> */
    private function health(): array
    {
        $jobs = (new JobQueueInsights($this->db))->summary(24);
        unset($jobs['failing_types']);

        $live = $this->lastLiveCheck();
        $byTarget = [];
        foreach (($live['results'] ?? []) as $r) {
            $byTarget[(string) $r['target']] = $r;
        }

        $integration = function (string $id, string $label, bool $configured, ?string $liveTarget, string $settings) use ($byTarget): array {
            $check = $liveTarget !== null ? ($byTarget[$liveTarget] ?? null) : null;
            $state = ! $configured ? 'not_configured'
                : ($check === null ? 'configured' : (! empty($check['skipped']) ? 'skipped' : ($check['ok'] ? 'ok' : 'failing')));

            return [
                'id'         => $id,
                'label'      => $label,
                'state'      => $state,
                'message'    => $check['message'] ?? ($configured ? 'Configured; no live check recorded yet.' : 'Not configured.'),
                'settings'   => $settings,
            ];
        };

        $sc = $this->searchConsoleConnection();
        $integrations = [
            $integration('gemini', 'Gemini (vetting, insights)', ReachConfig::secret('ai.gemini.api_key') !== null, 'gemini', '/admin/ai-automation#ai-providers'),
            $integration('openai', 'OpenAI (covers, voice-over)', ReachConfig::secret('ai.openai.api_key') !== null, 'openai', '/admin/ai-automation#ai-providers'),
            $integration('dataforseo', 'DataForSEO (rank tracker)', ReachConfig::secret('seo.dataforseo.password') !== null && (string) ReachConfig::get('seo.dataforseo.login', '') !== '', 'dataforseo', '/admin/ai-automation#seo'),
            $integration('social_api', 'social-api.ai (social, YouTube)', ReachConfig::secret('social.socialapi.api_key') !== null, 'social_api', '/admin/ai-automation#social'),
            $integration('shotstack', 'Shotstack (video render)', ReachConfig::secret('video.shotstack.api_key') !== null, 'shotstack', '/admin/ai-automation#video'),
            [
                'id'       => 'search_console',
                'label'    => 'Google Search Console',
                'state'    => $sc === null ? 'not_configured' : (in_array($sc['health_status'] ?? '', ['healthy', 'ok'], true) ? 'ok' : 'configured'),
                'message'  => $sc === null ? 'No enabled Search Console connection.'
                    : ('Property ' . $sc['site_property'] . ($sc['last_successful_ingest'] ? ' · last ingest ' . substr((string) $sc['last_successful_ingest'], 0, 16) : '')),
                'settings' => '/seo-centre/connections',
            ],
        ];

        $mode = null;
        try {
            $mode = SchemaGuard::hasTable($this->db, 'reach_bot_settings') ? (new \App\Models\BotSettingModel())->currentMode() : null;
        } catch (\Throwable) {
        }

        return [
            'bot_mode'       => $mode,
            'live_checks_at' => $live['ran_at'] ?? null,
            'integrations'   => $integrations,
            'queues'         => $jobs,
            'routines'       => $this->routines(),
        ];
    }

    /**
     * Last activity per automated content stream, and whether it looks stale
     * (enabled but nothing received for 36 h).
     *
     * @return list<array<string, mixed>>
     */
    private function routines(): array
    {
        $streams = [
            ['blog', 'Blog', 'reach_routine_submissions', "content_type = 'blog'", 'automation.blog.enabled'],
            ['knowledge_base', 'Knowledge Base', 'reach_routine_submissions', "content_type = 'knowledge_base'", 'automation.knowledge_base.enabled'],
            ['release_note', 'Release notes', 'reach_structured_content_submissions', "content_type = 'release_note'", 'automation.release_note.enabled'],
            ['workflow_guide', 'Workflow guides', 'reach_structured_content_submissions', "content_type = 'workflow_guide'", 'automation.workflow_guide.enabled'],
            ['community', 'Community Q&A', 'reach_community_automation_submissions', '1=1', 'automation.community.enabled'],
            ['video', 'Video', 'reach_video_pipeline_items', '1=1', 'automation.video.enabled'],
        ];
        $out = [];
        foreach ($streams as [$id, $label, $table, $where, $flag]) {
            $enabled = (bool) ReachConfig::get($flag, true);
            $row     = SchemaGuard::hasTable($this->db, $table)
                ? $this->db->query("SELECT MAX(created_at) AS last_at,
                        COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '24 hours') AS last_24h
                        FROM {$table} WHERE {$where}")->getRowArray()
                : null;
            $last  = $row['last_at'] ?? null;
            $stale = $enabled && ($last === null || strtotime((string) $last) < $this->now->getTimestamp() - 36 * 3600);
            $out[] = [
                'id'       => $id,
                'label'    => $label,
                'enabled'  => $enabled,
                'last_at'  => $last,
                'last_24h' => (int) ($row['last_24h'] ?? 0),
                'stale'    => $stale,
            ];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────── activity

    /**
     * What the bot did: 24 h / 7 d / 30 d counters per kind, and the newest
     * items across every kind. Counters and items come from
     * CommandCentreActivity, which also serves the per-tile drill-down
     * (`GET v1/dashboard/command-centre/activity`) from the same scope, so a
     * tile and its list always agree.
     *
     * @return array<string, mixed>
     */
    private function activity(): array
    {
        $activity = new CommandCentreActivity($this->db);
        $counts   = $activity->counters();
        $total30  = array_sum(array_column($counts, 'd30'));

        return [
            'status'   => $total30 === 0 ? 'empty' : 'ok',
            'note'     => $total30 === 0 ? 'Nothing automated has been recorded in the last 30 days yet.' : null,
            'counters' => $counts,
            'events'   => $total30 === 0 ? [] : $activity->items('', CommandCentreActivity::WINDOWS['30d'], 80),
        ];
    }

    // ───────────────────────────────────────────────────────────── impact

    /** @return array<string, mixed> */
    private function impact(): array
    {
        $to      = $this->now->format('Y-m-d');
        $from28  = $this->now->modify('-27 days')->format('Y-m-d');
        $prevTo  = $this->now->modify('-28 days')->format('Y-m-d');
        $prevFr  = $this->now->modify('-55 days')->format('Y-m-d');

        $search = ['available' => false, 'note' => 'No Search Console data ingested yet.'];
        $site   = new SearchSiteAnalyticsService($this->db);
        $latest = $site->latestDate();
        if ($latest !== null) {
            // Search Console lags ~3 days: anchor windows on the freshest day.
            $end   = new \DateTimeImmutable($latest);
            $cur   = $site->totals($end->modify('-27 days')->format('Y-m-d'), $latest);
            $prev  = $site->totals($end->modify('-55 days')->format('Y-m-d'), $end->modify('-28 days')->format('Y-m-d'));
            $daily = $site->daily($end->modify('-89 days')->format('Y-m-d'), $latest);
            $reach = $this->reachSearchShare($end->modify('-27 days')->format('Y-m-d'), $latest);

            $search = [
                'available'    => true,
                'latest_date'  => $latest,
                'window'       => ['from' => $end->modify('-27 days')->format('Y-m-d'), 'to' => $latest],
                'current'      => $cur,
                'previous'     => $prev,
                'change'       => [
                    'clicks'      => self::pctChange($cur['clicks'], $prev['clicks']),
                    'impressions' => self::pctChange($cur['impressions'], $prev['impressions']),
                ],
                'daily'        => array_map(static fn (array $d): array => [
                    'date' => $d['date'], 'clicks' => $d['clicks'], 'impressions' => $d['impressions'],
                ], $daily),
                'top_queries'  => $site->topQueries($end->modify('-27 days')->format('Y-m-d'), $latest, 5),
                'top_pages'    => $site->topPages($end->modify('-27 days')->format('Y-m-d'), $latest, 5),
                'reach_share'  => $reach + [
                    'share_of_clicks' => $cur['clicks'] > 0 ? round($reach['clicks'] / $cur['clicks'], 4) : null,
                ],
                // Under ~3 clicks a day trends are mostly noise; the page says so.
                'low_volume'   => $cur['clicks'] < 100,
            ];
        }

        $ga4 = ['available' => false];
        if (SchemaGuard::hasTable($this->db, 'reach_content_metric_facts')) {
            $g = $this->db->query(
                'SELECT COALESCE(SUM(sessions),0) AS sessions, COALESCE(SUM(users),0) AS users, COALESCE(SUM(page_views),0) AS views, COUNT(*) AS n
                   FROM reach_content_metric_facts WHERE metric_date BETWEEN ? AND ?',
                [$from28, $to]
            )->getRowArray();
            if ((int) $g['n'] > 0) {
                $ga4 = ['available' => true, 'sessions' => (int) $g['sessions'], 'users' => (int) $g['users'], 'page_views' => (int) $g['views']];
            }
        }

        $leads = ['available' => false];
        if (SchemaGuard::hasTable($this->db, 'reach_leads')) {
            $l = $this->db->query(
                "SELECT COUNT(*) FILTER (WHERE created_at::date BETWEEN ? AND ?) AS cur,
                        COUNT(*) FILTER (WHERE created_at::date BETWEEN ? AND ?) AS prev,
                        COUNT(*) FILTER (WHERE created_at::date BETWEEN ? AND ? AND engage_push_status = 'pushed') AS pushed,
                        COUNT(*) FILTER (WHERE engage_push_status IN ('failed','retry_scheduled')) AS push_problems,
                        COUNT(*) AS total
                   FROM reach_leads",
                [$from28, $to, $prevFr, $prevTo, $from28, $to]
            )->getRowArray();
            $bySource = $this->db->query(
                "SELECT COALESCE(NULLIF(source_kind, ''), 'unknown') AS source, COUNT(*) AS n FROM reach_leads
                  WHERE created_at::date BETWEEN ? AND ? GROUP BY 1 ORDER BY n DESC LIMIT 6",
                [$from28, $to]
            )->getResultArray();
            $leads = [
                'available'     => (int) $l['total'] > 0,
                'current'       => (int) $l['cur'],
                'previous'      => (int) $l['prev'],
                'change'        => self::pctChange((int) $l['cur'], (int) $l['prev']),
                'pushed'        => (int) $l['pushed'],
                'push_problems' => (int) $l['push_problems'],
                'by_source'     => array_map(static fn ($r) => ['source' => $r['source'], 'count' => (int) $r['n']], $bySource),
                'total'         => (int) $l['total'],
            ];
        }

        $outcomes = ['available' => false];
        if (SchemaGuard::hasTable($this->db, 'reach_lead_outcomes')) {
            $o = $this->db->query(
                "SELECT COUNT(*) AS events,
                        COUNT(DISTINCT COALESCE(lead_id::text, engage_lead_code)) AS leads,
                        COUNT(*) FILTER (WHERE LOWER(COALESCE(sales_status,'')) IN ('won','converted','closed_won','paid') OR LOWER(COALESCE(stage,'')) IN ('won','converted','customer','closed_won')) AS conversions,
                        COALESCE(SUM(value_inr) FILTER (WHERE LOWER(COALESCE(sales_status,'')) IN ('won','converted','closed_won','paid') OR LOWER(COALESCE(stage,'')) IN ('won','converted','customer','closed_won')), 0) AS revenue,
                        COUNT(*) FILTER (WHERE content_identity_id IS NOT NULL) AS content_attributed,
                        COUNT(*) FILTER (WHERE campaign_id IS NOT NULL) AS campaign_attributed
                   FROM reach_lead_outcomes WHERE occurred_at >= NOW() - INTERVAL '90 days'"
            )->getRowArray();
            $byStage = $this->db->query(
                "SELECT COALESCE(NULLIF(stage, ''), 'unknown') AS stage, COUNT(*) AS n FROM reach_lead_outcomes
                  WHERE occurred_at >= NOW() - INTERVAL '90 days' GROUP BY 1 ORDER BY n DESC LIMIT 8"
            )->getResultArray();
            $outcomes = [
                'available'           => (int) $o['events'] > 0,
                'window_days'         => 90,
                'events'              => (int) $o['events'],
                'leads'               => (int) $o['leads'],
                'conversions'         => (int) $o['conversions'],
                'revenue_inr'         => round((float) $o['revenue'], 2),
                'content_attributed'  => (int) $o['content_attributed'],
                'campaign_attributed' => (int) $o['campaign_attributed'],
                'by_stage'            => array_map(static fn ($r) => ['stage' => $r['stage'], 'count' => (int) $r['n']], $byStage),
            ];
        }

        $social = ['available' => false];
        if (SchemaGuard::hasTable($this->db, 'reach_social_posts')) {
            $s = $this->db->query(
                "SELECT COUNT(*) FILTER (WHERE status = 'posted' AND published_at >= NOW() - INTERVAL '28 days') AS posted,
                        COUNT(*) FILTER (WHERE status = 'failed' AND updated_at >= NOW() - INTERVAL '28 days') AS failed,
                        COUNT(*) FILTER (WHERE status = 'publishing') AS publishing
                   FROM reach_social_posts"
            )->getRowArray();
            $byChannel = $this->db->query(
                "SELECT channel, COUNT(*) AS n FROM reach_social_posts WHERE status = 'posted' AND published_at >= NOW() - INTERVAL '28 days'
                  GROUP BY channel ORDER BY n DESC"
            )->getResultArray();
            $social = [
                'available'  => ((int) $s['posted'] + (int) $s['failed'] + (int) $s['publishing']) > 0,
                'posted'     => (int) $s['posted'],
                'failed'     => (int) $s['failed'],
                'publishing' => (int) $s['publishing'],
                'by_channel' => array_map(static fn ($r) => ['channel' => $r['channel'], 'count' => (int) $r['n']], $byChannel),
                'note'       => 'Post counts only — engagement metrics are not ingested from the networks yet.',
            ];
        }

        $any = $search['available'] || $ga4['available'] || $leads['available'] || $outcomes['available'] || $social['available'];

        return [
            'status'   => $any ? 'ok' : 'empty',
            'note'     => $any ? null : 'No traffic, lead or outcome data has been collected yet.',
            'search'   => $search,
            'ga4'      => $ga4,
            'leads'    => $leads,
            'outcomes' => $outcomes,
            'social'   => $social,
        ];
    }

    /**
     * Clicks/impressions on pages Reach published (rows Search Console
     * ingestion mapped to a Reach content identity).
     *
     * @return array{clicks:int, impressions:int, pages:int}
     */
    private function reachSearchShare(string $from, string $to): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_search_metric_facts')) {
            return ['clicks' => 0, 'impressions' => 0, 'pages' => 0];
        }
        $r = $this->db->query(
            'SELECT COALESCE(SUM(clicks),0) AS c, COALESCE(SUM(impressions),0) AS i, COUNT(DISTINCT content_identity_id) AS p
               FROM reach_search_metric_facts WHERE metric_date BETWEEN ? AND ?',
            [$from, $to]
        )->getRowArray();

        return ['clicks' => (int) $r['c'], 'impressions' => (int) $r['i'], 'pages' => (int) $r['p']];
    }

    // ─────────────────────────────────────────────────────────── rankings

    /** @return array<string, mixed> */
    private function rankings(): array
    {
        // Built from the same rows as the drill-down (RankingsDrilldown), so the
        // tiles, their tooltips and the keyword table never disagree.
        $tracker = ['available' => false, 'note' => 'The SEO Tracker has not recorded a SERP check yet.'];
        if (RankingsDrilldown::comparisonDates($this->db)['latest'] !== null) {
            $kw   = (new RankingsDrilldown($this->db))->keywords();
            $sum  = $kw['summary'];
            $rows = $kw['keywords'];
            $ok   = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'ok'));
            $pick = static fn (array $r): array => ['keyword' => $r['keyword'], 'rank' => $r['our_rank'], 'url' => $r['our_url']];
            $ranked = array_values(array_filter($ok, static fn ($r) => $r['our_rank'] !== null));
            $movers = array_values(array_filter($ok, static fn ($r) => $r['change'] !== null && $r['change'] !== 0));
            $mover  = static fn (array $r): array => ['keyword' => $r['keyword'], 'rank' => $r['our_rank'], 'previous_rank' => $r['previous_rank'], 'change' => $r['change'], 'url' => $r['our_url']];
            $up   = array_values(array_filter($movers, static fn ($r) => $r['change'] > 0));
            $down = array_values(array_filter($movers, static fn ($r) => $r['change'] < 0));
            usort($up, static fn ($a, $b) => $b['change'] <=> $a['change']);
            usort($down, static fn ($a, $b) => $a['change'] <=> $b['change']);

            $tracker = [
                'available'        => true,
                'latest_date'      => $kw['latest_date'],
                'compared_with'    => $kw['compared_with'],
                'depth'            => $kw['depth'],
                'tracked'          => $sum['tracked'],
                'keywords_checked' => $sum['checked'],
                'failed'           => $sum['failed'],
                'not_checked'      => $sum['not_checked'],
                'ranked'           => $sum['ranked'],
                'top10'            => $sum['top10'],
                'top10_share'      => $sum['checked'] > 0 ? round($sum['top10'] / $sum['checked'], 4) : null,
                // Mean of our position over the keywords we rank for (not over every checked keyword).
                'avg_rank'         => $sum['avg_rank'],
                'avg_rank_over'    => $sum['ranked'],
                'top10_keywords'   => array_map($pick, array_values(array_filter($ranked, static fn ($r) => $r['our_rank'] <= 10))),
                'ranked_keywords'  => array_map($pick, array_slice($ranked, 0, 50)),
                'unranked_keywords' => array_slice(array_column(array_filter($ok, static fn ($r) => $r['our_rank'] === null), 'keyword'), 0, 50),
                'best'             => array_map($pick, array_slice($ranked, 0, 5)),
                'compared'         => count(array_filter($ok, static fn ($r) => $r['change'] !== null)),
                'unchanged'        => count(array_filter($ok, static fn ($r) => $r['change'] === 0)),
                'no_previous'      => count(array_filter($ok, static fn ($r) => ! $r['previous_checked'])),
                'movers_up'        => array_map($mover, array_slice($up, 0, 10)),
                'movers_down'      => array_map($mover, array_slice($down, 0, 10)),
                'competitors'      => array_map(static fn (array $c): array => [
                    'name'           => $c['name'],
                    'domain'         => $c['domain'],
                    'keywords'       => $c['keywords'],
                    'top10'          => $c['top10'],
                    // Mean of their best position over the `keywords` they appear for in the checked depth.
                    'avg_rank'       => $c['avg_rank'],
                    'top10_keywords' => array_map(static fn ($p) => ['keyword' => $p['keyword'], 'rank' => $p['rank'], 'our_rank' => $p['our_rank']],
                        array_values(array_filter($c['placements'], static fn ($p) => $p['rank'] <= 10))),
                ], array_slice($kw['competitors'], 0, 6)),
            ];
        }

        $visibility = ['available' => false, 'note' => 'No AI-visibility answers recorded yet.'];
        if (SchemaGuard::hasTable($this->db, 'reach_ai_visibility_runs')) {
            $sum = (new \App\Libraries\Intelligence\Visibility\AiVisibilityService($this->db))->summary(28);
            $answers = (int) ($sum['overall']['answers'] ?? 0);
            if ($answers > 0) {
                $counted = (int) ($sum['overall']['brand_answers_counted'] ?? 0);
                $visibility = [
                    'available'     => true,
                    'period'        => $sum['period'],
                    'answers'       => $answers,
                    // Answers to questions that name Aicountly are not counted for the brand.
                    'brand_answers_counted' => $counted,
                    'brand_mentions' => (int) ($sum['overall']['brand_mentions'] ?? 0),
                    'measured'      => $counted > 0,
                    'mention_rate'  => $counted > 0 ? ($sum['overall']['brand_mention_rate'] ?? null) : null,
                    'avg_rank'      => $sum['overall']['brand_avg_rank'] ?? null,
                    'by_provider'   => array_map(static fn (array $p): array => [
                        'provider' => $p['provider'], 'answers' => $p['answers'], 'counted' => $p['brand_answers_counted'], 'brand_mentions' => $p['brand_mentions'],
                    ], $sum['by_provider'] ?? []),
                    'failed_runs'   => array_sum(array_column($sum['failures'] ?? [], 'failed_runs')),
                    'top_competitors' => array_slice($sum['top_competitors'] ?? [], 0, 5),
                    'note'          => $sum['sample_note'] ?? null,
                ];
            }
        }

        $any = $tracker['available'] || $visibility['available'];

        return [
            'status'     => $any ? 'ok' : 'empty',
            'note'       => $any ? null : 'No ranking data yet: the SEO Tracker and AI-visibility checks have not produced results.',
            'tracker'    => $tracker,
            'visibility' => $visibility,
        ];
    }

    // ──────────────────────────────────────────────────────── projections

    /** @return array<string, mixed> */
    private function projections(): array
    {
        $forecasts = [];

        $site   = new SearchSiteAnalyticsService($this->db);
        $latest = $site->latestDate();
        if ($latest !== null) {
            $end   = new \DateTimeImmutable($latest);
            $daily = $site->daily($end->modify('-89 days')->format('Y-m-d'), $latest);
            $forecasts['clicks']      = ['label' => 'Search clicks (site-wide)'] + Forecast::daily(array_column($daily, 'clicks', 'date'), 28);
            $forecasts['impressions'] = ['label' => 'Search impressions (site-wide)'] + Forecast::daily(array_column($daily, 'impressions', 'date'), 28, ['low_volume' => 50]);
        } else {
            $forecasts['clicks'] = ['label' => 'Search clicks (site-wide)', 'available' => false, 'points' => 0, 'reason' => 'No Search Console data ingested yet.'];
        }

        if (SchemaGuard::hasTable($this->db, 'reach_leads')) {
            $rows = $this->db->query(
                "SELECT created_at::date::text AS d, COUNT(*) AS n FROM reach_leads
                  WHERE created_at >= NOW() - INTERVAL '90 days' GROUP BY 1 ORDER BY 1"
            )->getResultArray();
            $byDate = array_map('intval', array_column($rows, 'n', 'd'));
            if ($byDate !== []) {
                // A day with no lead is a real zero up to today, not missing data.
                $byDate[$this->now->format('Y-m-d')] ??= 0;
            }
            $forecasts['leads'] = ['label' => 'Leads captured'] + Forecast::daily($byDate, 28, ['low_volume' => 3]);
        }

        return [
            'status'    => array_filter($forecasts, static fn ($f) => $f['available'] ?? false) === [] ? 'empty' : 'ok',
            'note'      => 'Forecasts appear once there are at least ' . Forecast::MIN_POINTS . ' days of data; small volumes give wide bands.',
            'forecasts' => $forecasts,
            'roadmap'   => $this->roadmap(14),
            'quotas'    => $this->quotas(),
        ];
    }

    /**
     * Scheduled / planned items in the next N days, by day and type.
     *
     * @return array<string, mixed>
     */
    private function roadmap(int $days): array
    {
        $from  = $this->now->format('Y-m-d') . ' 00:00:00';
        $until = $this->now->modify("+{$days} days")->format('Y-m-d') . ' 23:59:59';
        $items = [];

        if (SchemaGuard::hasTable($this->db, 'reach_content_items')) {
            $sql = "SELECT i.id, i.title, i.content_type, i.scheduled_at AS at, 'content' AS source
                      FROM reach_content_items i
                     WHERE i.deleted_at IS NULL AND i.workflow_status IN ('scheduled','approved') AND i.scheduled_at BETWEEN ? AND ?";
            $binds = [$from, $until];
            if (SchemaGuard::hasTable($this->db, 'reach_content_schedules')) {
                $sql .= " UNION ALL
                    SELECT i.id, i.title, i.content_type, s.scheduled_at, 'schedule'
                      FROM reach_content_schedules s JOIN reach_content_items i ON i.id = s.content_item_id AND i.deleted_at IS NULL
                     WHERE s.cancelled_at IS NULL AND s.schedule_status IN ('pending','approved','ready') AND s.scheduled_at BETWEEN ? AND ?";
                array_push($binds, $from, $until);
            }
            if (SchemaGuard::hasTable($this->db, 'reach_publication_deployments')) {
                $sql .= " UNION ALL
                    SELECT i.id, i.title, i.content_type, d.scheduled_at, 'deployment'
                      FROM reach_publication_deployments d JOIN reach_content_items i ON i.id = d.content_item_id AND i.deleted_at IS NULL
                     WHERE d.status IN ('queued','scheduled') AND d.scheduled_at BETWEEN ? AND ?";
                array_push($binds, $from, $until);
            }
            $seen = [];
            foreach ($this->db->query($sql . ' ORDER BY 4', $binds)->getResultArray() as $r) {
                if (isset($seen[$r['id']])) {
                    continue;
                }
                $seen[$r['id']] = true;
                $items[] = ['id' => (int) $r['id'], 'title' => $r['title'], 'type' => $r['content_type'], 'date' => substr((string) $r['at'], 0, 10), 'source' => $r['source']];
            }
        }
        if (SchemaGuard::hasTable($this->db, 'reach_content_calendar_items')) {
            foreach ($this->db->query(
                'SELECT id, title, item_kind, date::text AS d FROM reach_content_calendar_items WHERE date BETWEEN ? AND ? ORDER BY date',
                [substr($from, 0, 10), substr($until, 0, 10)]
            )->getResultArray() as $r) {
                $items[] = ['id' => (int) $r['id'], 'title' => $r['title'], 'type' => $r['item_kind'], 'date' => $r['d'], 'source' => 'calendar'];
            }
        }
        usort($items, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        $byType = [];
        foreach ($items as $it) {
            $byType[$it['type']] = ($byType[$it['type']] ?? 0) + 1;
        }

        return ['days' => $days, 'items' => array_slice($items, 0, 40), 'total' => count($items), 'by_type' => $byType];
    }

    /**
     * What the routines are set to produce per day (Settings → AI &
     * Automation), so "expected output" is the configured quota, not a guess.
     *
     * @return list<array<string, mixed>>
     */
    private function quotas(): array
    {
        $defs = [
            ['blog', 'Blogs', 'automation.blog.enabled', 'automation.blog.daily_limit'],
            ['knowledge_base', 'Help articles', 'automation.knowledge_base.enabled', 'automation.knowledge_base.daily_limit'],
            ['community', 'Community answers', 'automation.community.enabled', 'automation.community.daily_limit'],
            ['release_note', 'Release notes (cap)', 'automation.release_note.enabled', 'automation.release_note.daily_limit'],
            ['workflow_guide', 'Workflow guides (cap)', 'automation.workflow_guide.enabled', 'automation.workflow_guide.daily_limit'],
            ['video_vlog', 'Vlogs', 'automation.video.enabled', 'video.daily.vlogs'],
            ['video_short', 'Shorts', 'automation.video.enabled', 'video.daily.shorts'],
        ];
        $out = [];
        foreach ($defs as [$id, $label, $flag, $limit]) {
            $enabled = (bool) ReachConfig::get($flag, true);
            $perDay  = (int) ReachConfig::get($limit, 0);
            $out[] = ['id' => $id, 'label' => $label, 'enabled' => $enabled, 'per_day' => $enabled ? $perDay : 0, 'next_14_days' => $enabled ? $perDay * 14 : 0];
        }

        return $out;
    }

    // ──────────────────────────────────────────────────── recommendations

    /**
     * Action cards from real signals, most urgent first. `action` is a
     * one-click API call where Reach has one (method + endpoint +
     * permission), otherwise `link` points at the screen that fixes it.
     *
     * @return array<string, mixed>
     */
    private function recommendations(): array
    {
        $health = $this->built['health'] ?? $this->section('health', false);
        $cards  = [];
        $add    = static function (string $id, string $severity, string $title, string $detail, ?array $action = null, ?string $link = null, string $source = 'signal') use (&$cards): void {
            $cards[] = compact('id', 'severity', 'title', 'detail', 'action', 'link', 'source');
        };

        // Integrations: not configured / failing.
        foreach (($health['integrations'] ?? []) as $i) {
            if ($i['state'] === 'failing') {
                $add('integration-' . $i['id'], 'high', $i['label'] . ' is failing its live check', (string) $i['message'],
                    ['label' => 'Run live checks', 'method' => 'POST', 'endpoint' => 'v1/settings/automation/live-check', 'body' => ['only' => [$i['id']]], 'permission' => 'settings.manage'],
                    $i['settings'], 'integration');
            } elseif ($i['state'] === 'not_configured') {
                $add('integration-' . $i['id'], in_array($i['id'], ['gemini', 'search_console'], true) ? 'high' : 'medium',
                    $i['label'] . ' is not configured', 'Features that depend on it are skipped until it is set up.', null, $i['settings'], 'settings');
            }
        }

        // DataForSEO balance from the last live check.
        $live = $this->lastLiveCheck();
        foreach (($live['results'] ?? []) as $r) {
            $bal = $r['detail']['balance_usd'] ?? null;
            if ($r['target'] === 'dataforseo' && is_numeric($bal) && (float) $bal < 5) {
                $add('dataforseo-balance', 'medium', sprintf('DataForSEO balance is low ($%.2f)', (float) $bal),
                    'Daily SERP checks stop when the balance runs out. Top up the DataForSEO account.', null, '/admin/ai-automation#seo', 'integration');
            }
        }

        // Queues.
        $queues = $health['queues'] ?? [];
        foreach (($queues['queues'] ?? []) as $q) {
            if ($q['undrained'] ?? false) {
                $add('queue-' . $q['queue'], 'high', "Queue \"{$q['queue']}\" is not being drained",
                    "{$q['queued']} job(s) waiting and nothing finished on it for an hour — add it to the worker's --queue= list.",
                    null, '/admin/jobs?' . $q['filters'], 'queue');
            }
        }
        foreach ((new JobQueueInsights($this->db))->failingTypes(24, 5) as $t) {
            if ($t['failed'] > 0) {
                $add('failing-' . $t['job_type'], $t['failed'] >= 5 ? 'high' : 'medium', "{$t['job_type']} failed {$t['failed']}× in 24 h",
                    'Last error: ' . mb_substr((string) $t['last_error'], 0, 220), null, '/admin/jobs?' . $t['filters'], 'queue');
            }
        }
        if (($queues['stalled'] ?? 0) > 0) {
            $add('stalled', 'medium', "{$queues['stalled']} job(s) stalled", 'A worker died mid-job (lease expired). reach:schedule re-queues them each minute.', null, '/admin/jobs?stalled=1', 'queue');
        }

        // Stalled routines.
        foreach (($health['routines'] ?? []) as $r) {
            if ($r['stale']) {
                $add('routine-' . $r['id'], 'medium', "{$r['label']} routine has been quiet",
                    $r['last_at'] ? 'Last submission ' . substr((string) $r['last_at'], 0, 16) . ' — check its Claude routine schedule.' : 'No submission received yet — check its Claude routine.',
                    null, '/admin/ai-automation#automation', 'pipeline');
            }
        }

        // Stuck publishing pipeline.
        if (SchemaGuard::hasTable($this->db, 'reach_content_items')) {
            $stuck = (int) $this->db->query(
                "SELECT COUNT(*) AS c FROM reach_content_items WHERE deleted_at IS NULL
                   AND workflow_status IN ('publish_queued','publishing','verification_pending','ready_for_publication')
                   AND updated_at < NOW() - INTERVAL '24 hours'"
            )->getRowArray()['c'];
            if ($stuck > 0) {
                $add('stuck-publishing', 'medium', "{$stuck} item(s) stuck on the way to publication",
                    'In the publish queue, publishing or awaiting verification for over 24 hours.', null,
                    '/content?workflow_status=publish_queued,publishing,verification_pending,ready_for_publication', 'pipeline');
            }
        }
        if (SchemaGuard::hasTable($this->db, 'reach_routine_submissions')) {
            $blocked = (int) $this->db->query("SELECT COUNT(*) AS c FROM reach_routine_submissions WHERE status IN ('publish_blocked','publish_failed')")->getRowArray()['c'];
            if ($blocked > 0) {
                $add('routine-blocked', 'medium', "{$blocked} routine article(s) blocked from publishing", 'Open each one for the blocking reason and retry.', null, '/blog-command-centre/verification/routine', 'pipeline');
            }
        }

        // Search opportunities (site-wide, last 28 days of data).
        $site   = new SearchSiteAnalyticsService($this->db);
        $latest = $site->latestDate();
        if ($latest !== null) {
            $from = (new \DateTimeImmutable($latest))->modify('-27 days')->format('Y-m-d');
            foreach ($this->db->query(
                "SELECT query, SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(avg_position * impressions) / NULLIF(SUM(impressions),0) AS pos
                   FROM reach_search_site_facts WHERE metric_date BETWEEN ? AND ? AND query <> ''
                  GROUP BY query HAVING SUM(impressions) >= 20
                     AND SUM(avg_position * impressions) / NULLIF(SUM(impressions),0) BETWEEN 4 AND 15
                  ORDER BY SUM(impressions) DESC LIMIT 3",
                [$from, $latest]
            )->getResultArray() as $q) {
                $add('push-' . md5($q['query']), 'low', sprintf('Push "%s" onto page one', $q['query']),
                    sprintf('Average position %.1f with %d impressions and %d clicks in 28 days — a focused article or internal links could lift it.', (float) $q['pos'], (int) $q['imp'], (int) $q['clk']),
                    null, '/intelligence/search', 'search');
            }
            foreach ($this->db->query(
                "SELECT page_url, SUM(impressions) AS imp, SUM(clicks) AS clk
                   FROM reach_search_site_facts WHERE metric_date BETWEEN ? AND ? AND page_url <> ''
                  GROUP BY page_url HAVING SUM(impressions) >= 100 AND SUM(clicks)::float / NULLIF(SUM(impressions),0) < 0.01
                  ORDER BY SUM(impressions) DESC LIMIT 2",
                [$from, $latest]
            )->getResultArray() as $p) {
                $add('retitle-' . md5($p['page_url']), 'low', 'Retitle a page people see but do not click',
                    sprintf('%s — %d impressions, %d clicks (CTR %.1f%%). A clearer title and description usually help.', $p['page_url'], (int) $p['imp'], (int) $p['clk'], 100 * (int) $p['clk'] / max(1, (int) $p['imp'])),
                    null, '/intelligence/search/pages', 'search');
            }
        }

        // AI Insights (stream H) open recommendations.
        if (SchemaGuard::hasTable($this->db, 'reach_ai_insight_actions')) {
            foreach ($this->db->query(
                "SELECT a.id, a.title, a.detail, a.expected_impact, a.effort FROM reach_ai_insight_actions a
                   JOIN reach_ai_insight_reports r ON r.id = a.report_id
                  WHERE a.status = 'open' AND r.status = 'completed'
                  ORDER BY a.report_id DESC, a.position ASC LIMIT 4"
            )->getResultArray() as $a) {
                $add('insight-' . $a['id'], 'low', (string) $a['title'],
                    trim(mb_substr((string) $a['detail'], 0, 240) . ($a['expected_impact'] ? ' · impact ' . $a['expected_impact'] : '') . ($a['effort'] ? ' · effort ' . $a['effort'] : '')),
                    ['label' => 'Mark done', 'method' => 'PATCH', 'endpoint' => 'v1/intelligence/insights/actions/' . $a['id'], 'body' => ['status' => 'done'], 'permission' => 'intelligence.manage'],
                    '/intelligence/insights', 'ai_insights');
            }
        }

        // Nothing ranked yet → run the tracker; no insights yet → run insights.
        $rank = $this->built['rankings'] ?? null;
        if (is_array($rank) && ! ($rank['tracker']['available'] ?? false)
            && ($this->integrationState($health, 'dataforseo') !== 'not_configured')) {
            $add('run-tracker', 'low', 'Run the SEO Tracker now', 'No SERP check has been recorded yet.',
                ['label' => 'Run now', 'method' => 'POST', 'endpoint' => 'v1/seo/tracker/run', 'body' => [], 'permission' => 'seo.manage'], '/seo-centre/tracker', 'rankings');
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($cards, static fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return [
            'status' => $cards === [] ? 'empty' : 'ok',
            'note'   => $cards === [] ? 'No action needed right now — every signal Reach checks is healthy.' : null,
            'cards'  => array_slice($cards, 0, 16),
        ];
    }

    // ─────────────────────────────────────────────────────────── helpers

    /** @return array<string, mixed> */
    private function lastLiveCheck(): array
    {
        try {
            $v = (new SettingModel())->getSetting(self::LIVE_CHECK_SETTING);

            return is_array($v) ? $v : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    private function searchConsoleConnection(): ?array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_analytics_connections')) {
            return null;
        }

        return $this->db->query(
            "SELECT id, site_property, health_status, last_successful_ingest FROM reach_analytics_connections
              WHERE provider = 'gsc' AND enabled = TRUE AND revoked_at IS NULL ORDER BY id LIMIT 1"
        )->getRowArray();
    }

    /** @param array<string, mixed> $health */
    private function integrationState(array $health, string $id): ?string
    {
        foreach (($health['integrations'] ?? []) as $i) {
            if ($i['id'] === $id) {
                return $i['state'];
            }
        }

        return null;
    }

    private static function pctChange(int|float $cur, int|float $prev): ?float
    {
        return $prev > 0 ? round(($cur - $prev) / $prev, 4) : null;
    }
}

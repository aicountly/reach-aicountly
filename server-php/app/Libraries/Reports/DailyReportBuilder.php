<?php

declare(strict_types=1);

namespace App\Libraries\Reports;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Gateways\Mailer;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\GoogleSearchConsoleConnector;
use App\Libraries\Intelligence\SearchSiteAnalyticsService;
use App\Libraries\Intelligence\Seo\SeoTrackerService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Collects the daily analytics report as plain data (summary_json). Every
 * section carries `configured` and, when its source is missing, a `note`
 * saying so — a section never shows invented or placeholder figures, and one
 * failing source never takes the rest of the report down with it.
 *
 * Sections: search, traffic, seo_tracker, content, vetting, pipeline, todo.
 */
class DailyReportBuilder
{
    public const TIMEZONE = 'Asia/Kolkata';

    /** Content types the report always lists (null count = source not present yet). */
    public const CONTENT_TYPES = [
        'blog'           => 'Blog posts',
        'community'      => 'Community answers',
        'knowledge_base' => 'Knowledge base articles',
        'release_note'   => 'Release notes',
        'workflow_guide' => 'Workflow guides',
    ];

    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private ?Ga4DailySnapshot $ga4 = null,
        private ?SeoTrackerService $tracker = null,
        private ?\Closure $searchConfigured = null,
        private ?\Closure $emailProviders = null,
    ) {
        $this->db  = $db ?? Database::connect();
        $this->ga4 ??= new Ga4DailySnapshot();
    }

    /**
     * @param string                  $reportDate IST date the report is for (Y-m-d)
     * @param \DateTimeImmutable|null $now        clock override (tests)
     * @return array<string, mixed>
     */
    public function build(string $reportDate, ?\DateTimeImmutable $now = null): array
    {
        $tz  = new \DateTimeZone(self::TIMEZONE);
        $now ??= new \DateTimeImmutable('now', $tz);

        // The report covers the 24 hours up to 08:00 IST on its date, or up to
        // now when it is generated earlier than that (a preview).
        $cutoff = new \DateTimeImmutable($reportDate . ' 08:00:00', $tz);
        $end    = $cutoff < $now ? $cutoff : $now;
        $start  = $end->modify('-24 hours');
        $window = [
            'start' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
            'end'   => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
        ];

        $sections = [
            'search'      => $this->guard('search', fn () => $this->search()),
            'traffic'     => $this->guard('traffic', fn () => $this->traffic($reportDate)),
            'seo_tracker' => $this->guard('seo_tracker', fn () => $this->seoTracker()),
            'content'     => $this->guard('content', fn () => $this->content($window)),
            'vetting'     => $this->guard('vetting', fn () => $this->vetting($window)),
            'pipeline'    => $this->guard('pipeline', fn () => $this->pipeline($window)),
        ];
        $sections['todo'] = ['configured' => true, 'items' => self::todo($sections)];

        return [
            'report_date'  => $reportDate,
            'generated_at' => $now->format('Y-m-d H:i:sP'),
            'timezone'     => self::TIMEZONE,
            'window'       => $window,
            'sections'     => $sections,
        ];
    }

    // ── sections ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function search(): array
    {
        $svc    = new SearchSiteAnalyticsService($this->db);
        $latest = $svc->latestDate();

        if ($latest === null) {
            $configured = $this->searchConsoleConfigured();

            return [
                'configured' => $configured,
                'note'       => $configured
                    ? 'Search Console is configured but no data has been ingested yet (Google publishes with a ~3 day lag; the ingest runs daily at 04:00 UTC).'
                    : 'Not configured — Search Console is not connected. See docs/operations/SEARCH_CONSOLE_SETUP.md.',
            ];
        }

        $baseFrom = date('Y-m-d', strtotime($latest . ' -7 days'));
        $baseTo   = date('Y-m-d', strtotime($latest . ' -1 day'));
        $day      = $svc->totals($latest, $latest);
        $base     = $svc->totals($baseFrom, $baseTo);
        $baseDays = max(1, $base['days']);

        return [
            'configured' => true,
            'date'       => $latest,
            'day'        => $day,
            'baseline'   => [
                'from'         => $baseFrom,
                'to'           => $baseTo,
                'days'         => $base['days'],
                'clicks'       => $base['days'] > 0 ? round($base['clicks'] / $baseDays, 1) : null,
                'impressions'  => $base['days'] > 0 ? round($base['impressions'] / $baseDays, 1) : null,
                'ctr'          => $base['ctr'],
                'avg_position' => $base['avg_position'],
            ],
            'top_queries' => $svc->topQueries($latest, $latest, 10),
            'top_pages'   => $svc->topPages($latest, $latest, 10),
            'movers'      => $svc->movers($latest, $baseFrom, $baseTo, 5),
        ];
    }

    /** @return array<string, mixed> */
    private function traffic(string $reportDate): array
    {
        if (! $this->ga4->isConfigured()) {
            return ['configured' => false, 'note' => 'Not configured — set GA4_PROPERTY_ID_MARKETING and GOOGLE_SERVICE_ACCOUNT_JSON_MARKETING.'];
        }
        $yesterday = date('Y-m-d', strtotime($reportDate . ' -1 day'));
        $data      = $this->ga4->fetch($yesterday);
        if (isset($data['error'])) {
            return ['configured' => true, 'error' => $data['error'], 'note' => 'GA4 is configured but the request failed: ' . $data['error']];
        }

        return $data + ['configured' => true];
    }

    /** @return array<string, mixed> */
    private function seoTracker(): array
    {
        $tracker = $this->tracker ?? new SeoTrackerService($this->db);
        $table   = $tracker->keywordTable();
        $status  = $table['status'];

        if ($table['date'] === null) {
            return [
                'configured' => $status['status'] === 'ready',
                'status'     => $status['status'],
                'note'       => $status['status'] === 'ready'
                    ? 'DataForSEO is configured but no SERP check has completed yet (runs daily at 06:00 IST).'
                    : 'Not configured — ' . $status['detail'],
            ];
        }

        $beaten = [];
        $up     = [];
        $down   = [];
        foreach ($table['keywords'] as $k) {
            if (($k['check_status'] ?? null) !== 'ok') {
                continue;
            }
            if ($k['winner'] !== null && ! $k['winner']['ours']) {
                $beaten[] = ['keyword' => $k['keyword'], 'priority' => $k['priority'], 'our_rank' => $k['our_rank'],
                    'competitor' => $k['winner']['name'], 'competitor_rank' => $k['winner']['rank']];
            }
            if ($k['change'] !== null && $k['change'] > 0) {
                $up[] = ['keyword' => $k['keyword'], 'our_rank' => $k['our_rank'], 'previous_rank' => $k['previous_rank'], 'change' => $k['change']];
            }
            if ($k['change'] !== null && $k['change'] < 0) {
                $down[] = ['keyword' => $k['keyword'], 'our_rank' => $k['our_rank'], 'previous_rank' => $k['previous_rank'], 'change' => $k['change']];
            }
        }
        $prio = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($beaten, static fn ($a, $b) => [$prio[$a['priority']] ?? 3, $a['competitor_rank']] <=> [$prio[$b['priority']] ?? 3, $b['competitor_rank']]);
        usort($up, static fn ($a, $b) => $b['change'] <=> $a['change']);
        usort($down, static fn ($a, $b) => $a['change'] <=> $b['change']);

        return [
            'configured'  => true,
            'status'      => $status['status'],
            'date'        => $table['date'],
            'summary'     => $table['summary'],
            'up'          => array_slice($up, 0, 5),
            'down'        => array_slice($down, 0, 5),
            'beaten'      => array_slice($beaten, 0, 10),
            'top10_share' => array_slice($table['top10_share'], 0, 6),
        ];
    }

    /**
     * @param array{start:string, end:string} $window
     * @return array<string, mixed>
     */
    private function content(array $window): array
    {
        $counts = array_fill_keys(array_keys(self::CONTENT_TYPES), null);
        $items  = [];

        if (SchemaGuard::hasTable($this->db, 'reach_content_items')) {
            foreach (['blog', 'knowledge_base'] as $type) {
                $counts[$type] = 0;
            }
            $rows = $this->db->query(
                'SELECT id, content_type, title, published_at FROM reach_content_items
                 WHERE published_at >= ? AND published_at < ? AND deleted_at IS NULL
                 ORDER BY published_at DESC',
                [$window['start'], $window['end']]
            )->getResultArray();
            foreach ($rows as $row) {
                $type = match ((string) $row['content_type']) {
                    'community_answer', 'community_question' => 'community',
                    'release_announcement', 'release_note'    => 'release_note',
                    default                                   => (string) $row['content_type'],
                };
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                if (count($items) < 15) {
                    $items[] = ['type' => $type, 'title' => (string) $row['title'], 'published_at' => $row['published_at']];
                }
            }
        }

        if (SchemaGuard::hasTable($this->db, 'reach_community_deployments')) {
            $community = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM reach_community_deployments WHERE status = 'succeeded' AND deployed_at >= ? AND deployed_at < ?",
                [$window['start'], $window['end']]
            )->getRowArray()['c'] ?? 0);
            $counts['community'] = ($counts['community'] ?? 0) + $community;
        }

        // Tables other pipelines may add (release notes, workflow guides).
        foreach (['release_note' => ['reach_release_notes'], 'workflow_guide' => ['reach_workflow_guides']] as $type => $tables) {
            foreach ($tables as $table) {
                if (SchemaGuard::hasTable($this->db, $table) && SchemaGuard::hasColumn($this->db, $table, 'published_at')) {
                    $n = (int) ($this->db->query(
                        "SELECT COUNT(*) AS c FROM {$table} WHERE published_at >= ? AND published_at < ?",
                        [$window['start'], $window['end']]
                    )->getRowArray()['c'] ?? 0);
                    $counts[$type] = ($counts[$type] ?? 0) + $n;
                }
            }
        }

        $total = array_sum(array_filter($counts, static fn ($v) => $v !== null));

        return ['configured' => true, 'counts' => $counts, 'total' => $total, 'items' => $items];
    }

    /**
     * @param array{start:string, end:string} $window
     * @return array<string, mixed>
     */
    private function vetting(array $window): array
    {
        $table = 'reach_ai_vetting_runs';
        if (! SchemaGuard::hasTable($this->db, $table)) {
            return ['configured' => false, 'note' => 'Not configured — AI vetting runs are not recorded on this deployment yet.'];
        }

        $fields = $this->db->getFieldNames($table);
        $decisionCol = in_array('decision', $fields, true) ? 'decision' : null;
        $timeCol     = in_array('created_at', $fields, true) ? 'created_at' : (in_array('started_at', $fields, true) ? 'started_at' : null);
        if ($decisionCol === null || $timeCol === null) {
            return ['configured' => false, 'note' => 'reach_ai_vetting_runs has no decision/created_at columns; cannot summarise.'];
        }
        $reasonsCol = null;
        foreach (['reasons_json', 'reasons'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $reasonsCol = $candidate;
                break;
            }
        }
        $typeCol = in_array('content_type', $fields, true) ? 'content_type' : null;

        $rows = $this->db->query(
            "SELECT {$decisionCol} AS decision" . ($reasonsCol ? ", {$reasonsCol}::text AS reasons" : '')
            . ($typeCol ? ", {$typeCol} AS content_type" : '')
            . " FROM {$table} WHERE {$timeCol} >= ? AND {$timeCol} < ?",
            [$window['start'], $window['end']]
        )->getResultArray();

        $counts  = ['approve' => 0, 'revise' => 0, 'reject' => 0, 'other' => 0];
        $byType  = [];
        $reasons = [];
        foreach ($rows as $row) {
            $decision = strtolower((string) $row['decision']);
            $key      = isset($counts[$decision]) ? $decision : 'other';
            $counts[$key]++;
            if (isset($row['content_type'])) {
                $byType[(string) $row['content_type']][$key] = ($byType[(string) $row['content_type']][$key] ?? 0) + 1;
            }
            if ($key === 'reject' && ! empty($row['reasons'])) {
                $decoded = json_decode((string) $row['reasons'], true);
                $list    = is_array($decoded) ? $decoded : [(string) $row['reasons']];
                foreach ($list as $reason) {
                    $text = trim(is_string($reason) ? $reason : (string) json_encode($reason));
                    if ($text !== '') {
                        $text = mb_substr($text, 0, 140);
                        $reasons[$text] = ($reasons[$text] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($reasons);
        $top = [];
        foreach (array_slice($reasons, 0, 5, true) as $text => $n) {
            $top[] = ['reason' => $text, 'count' => $n];
        }

        return ['configured' => true, 'total' => count($rows), 'counts' => $counts, 'by_type' => $byType, 'top_reject_reasons' => $top,
                'quality' => $this->quality($window)];
    }

    /**
     * Content clean-up / daily safety net (informational): re-vets of stored
     * content, pages taken down and same-slug rewrites in the queue.
     *
     * @param array{start:string, end:string} $window
     * @return array<string, int>|null
     */
    private function quality(array $window): ?array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_content_quality_checks')) {
            return null;
        }
        $summary = (new \App\Libraries\Content\Quality\QualityCheckLog($this->db))->summarySince($window['start']);
        $open    = (new \App\Libraries\Content\Quality\RegenerationQueue($this->db))->counts();

        return $summary + ['rewrites_open' => array_sum($open)];
    }

    /**
     * @param array{start:string, end:string} $window
     * @return array<string, mixed>
     */
    private function pipeline(array $window): array
    {
        $out = ['configured' => true, 'email' => $this->emailLine()];

        if (SchemaGuard::hasTable($this->db, 'reach_jobs')) {
            $q = fn (string $sql, array $b = []): int => (int) ($this->db->query($sql, $b)->getRowArray()['c'] ?? 0);
            $out['failed_jobs_24h'] = $q("SELECT COUNT(*) AS c FROM reach_jobs WHERE status = 'failed' AND updated_at >= ? AND updated_at < ?", [$window['start'], $window['end']]);
            $out['dead_letters'] = $q("SELECT COUNT(*) AS c FROM reach_jobs WHERE status = 'dead_letter'");
            $out['dead_letters_24h'] = $q("SELECT COUNT(*) AS c FROM reach_jobs WHERE status = 'dead_letter' AND updated_at >= ?", [$window['start']]);
            $out['stalled_jobs'] = $q("SELECT COUNT(*) AS c FROM reach_jobs WHERE status IN ('reserved','processing') AND lease_expires_at < NOW()");
            $out['pending_over_1h'] = $q("SELECT COUNT(*) AS c FROM reach_jobs WHERE status = 'pending' AND available_at < NOW() - INTERVAL '1 hour'");
            $out['top_failing'] = $this->db->query(
                "SELECT job_type, COUNT(*) AS c FROM reach_jobs
                 WHERE status IN ('failed','dead_letter') AND updated_at >= ?
                 GROUP BY job_type ORDER BY c DESC LIMIT 5",
                [$window['start']]
            )->getResultArray();
            $out['pending_by_queue'] = $this->db->query(
                "SELECT queue, COUNT(*) AS c FROM reach_jobs WHERE status = 'pending' GROUP BY queue ORDER BY c DESC"
            )->getResultArray();
        } else {
            $out['note'] = 'Job queue table not found.';
        }

        if (SchemaGuard::hasTable($this->db, 'reach_content_items')) {
            $out['stalled_content'] = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM reach_content_items
                 WHERE workflow_status IN ('validation_pending','review_pending','ready_for_publication')
                   AND updated_at < NOW() - INTERVAL '24 hours' AND deleted_at IS NULL"
            )->getRowArray()['c'] ?? 0);
        }
        if (SchemaGuard::hasTable($this->db, 'reach_publication_deployments')) {
            $out['failed_deployments_24h'] = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM reach_publication_deployments WHERE status = 'failed' AND updated_at >= ?",
                [$window['start']]
            )->getRowArray()['c'] ?? 0);
        }

        return $out;
    }

    // ── "Today at a glance" ────────────────────────────────────────────────

    /**
     * Rule-based, ordered by impact; at most 8 items. Pure so it can be tested.
     * Observations, not chores: routine content work (rewrites, refreshes,
     * topics) is the routines' job, so nothing here asks a person to do it.
     * The only asks left are one-time connections and broken plumbing.
     *
     * @param array<string, array<string, mixed>> $s
     * @return list<string>
     */
    public static function todo(array $s): array
    {
        $items = [];
        $search = $s['search'] ?? [];

        if (empty($search['configured'])) {
            $items[] = 'Connect Google Search Console — follow docs/operations/SEARCH_CONSOLE_SETUP.md, then run a 90-day backfill.';
        } elseif (isset($search['day'], $search['baseline'])) {
            $clicks = (float) ($search['day']['clicks'] ?? 0);
            $base   = (float) ($search['baseline']['clicks'] ?? 0);
            if ($base >= 5 && $clicks < $base * 0.8) {
                $items[] = sprintf('Search clicks on %s were %d vs a 7-day average of %.1f (%d%%); the declined queries are listed below.',
                    $search['date'], (int) $clicks, $base, (int) round(($clicks - $base) / $base * 100));
            }
            foreach (array_slice($search['movers']['declined'] ?? [], 0, 2) as $m) {
                $items[] = sprintf('"%s" slipped from position %.1f to %.1f.', $m['query'], $m['previous_position'], $m['position']);
            }
            $striking = array_filter($search['top_queries'] ?? [], static fn ($q) => ($q['avg_position'] ?? 0) > 3 && ($q['avg_position'] ?? 0) <= 15 && ($q['impressions'] ?? 0) >= 20);
            usort($striking, static fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
            foreach (array_slice($striking, 0, 2) as $q) {
                $items[] = sprintf('"%s" sits at position %.1f with %d impressions — within reach of the top 3.', $q['dimension_value'], $q['avg_position'], $q['impressions']);
            }
            foreach ($search['top_pages'] ?? [] as $p) {
                if (($p['impressions'] ?? 0) >= 100 && ($p['ctr'] ?? 1) < 0.01) {
                    $items[] = sprintf('%s has %d impressions but a %.1f%% CTR — its title/meta description draws few clicks.', $p['dimension_value'], $p['impressions'], ($p['ctr'] ?? 0) * 100);
                    break;
                }
            }
        }

        $tracker = $s['seo_tracker'] ?? [];
        if (empty($tracker['configured']) && ($tracker['status'] ?? '') === 'not_configured') {
            $items[] = 'Add DataForSEO credentials (Settings → AI & Automation → SEO data) to start tracking positions against competitors.';
        }
        foreach (array_slice($tracker['beaten'] ?? [], 0, 2) as $b) {
            $items[] = sprintf('%s outranks us for "%s" (#%d vs %s).', $b['competitor'], $b['keyword'], $b['competitor_rank'],
                $b['our_rank'] === null ? 'not in top 100' : '#' . $b['our_rank']);
        }

        $pipe = $s['pipeline'] ?? [];
        if (str_starts_with((string) ($pipe['email'] ?? ''), 'email: no provider')) {
            $items[] = 'No email provider is configured for Reach — add one in Console → Email providers (bound to reach); until then reports and campaign mail are not sent.';
        }
        if (($pipe['dead_letters'] ?? 0) > 0) {
            $items[] = sprintf('%d job(s) are dead-lettered — inspect and retry them in Job Monitor.', $pipe['dead_letters']);
        }
        if (($pipe['stalled_jobs'] ?? 0) > 0 || ($pipe['pending_over_1h'] ?? 0) > 0) {
            $items[] = sprintf('Queue is backing up (%d stalled, %d pending > 1h) — check the reach:work cron drains every queue.',
                $pipe['stalled_jobs'] ?? 0, $pipe['pending_over_1h'] ?? 0);
        }

        $vet = $s['vetting'] ?? [];
        if (! empty($vet['configured']) && ($vet['counts']['reject'] ?? 0) > ($vet['counts']['approve'] ?? 0) && ($vet['counts']['reject'] ?? 0) > 0) {
            $items[] = sprintf('Vetting rejected %d draft(s) vs %d approved; the top reject reasons are listed below.',
                $vet['counts']['reject'], $vet['counts']['approve'] ?? 0);
        }

        $content = $s['content'] ?? [];
        if (isset($content['total']) && (int) $content['total'] === 0) {
            $items[] = 'Nothing was published in the last 24 hours — check the content automation routines and the publishing queue.';
        }

        if ($items === []) {
            $items[] = 'No issues detected — keep the publishing cadence going.';
        }

        return array_slice($items, 0, 8);
    }

    /** "email via itwalk_api, fallback aicountly_smtp" — provider names only (EmailProviderResolver). */
    private function emailLine(): string
    {
        try {
            return $this->emailProviders !== null ? (string) ($this->emailProviders)() : Mailer::providerSummary()['line'];
        } catch (\Throwable) {
            return 'email: provider lookup failed';
        }
    }

    private function searchConsoleConfigured(): bool
    {
        if ($this->searchConfigured !== null) {
            return (bool) ($this->searchConfigured)();
        }
        $connector = ConnectorProviderFactory::searchConsole();
        if (! $connector->isEnabled()) {
            return false;
        }

        return $connector instanceof GoogleSearchConsoleConnector
            ? $connector->configurationState()['status'] === 'configured'
            : false; // the mock is never "configured" for a report
    }

    /**
     * @param callable(): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private function guard(string $section, callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            log_message('error', "DailyReportBuilder {$section}: " . $e->getMessage());

            return ['configured' => true, 'error' => $e->getMessage(), 'note' => 'This section could not be built: ' . $e->getMessage()];
        }
    }
}

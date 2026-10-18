<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\SearchSiteAnalyticsService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What actually happened, per IST day and metric, read straight from the
 * pipeline tables (one grouped query per metric for any date range).
 *
 * Values:
 *   outputs  — a count (0 when nothing happened: that is a real lag), or
 *              null only when the source table does not exist here;
 *   search   — null for a day Search Console has not reported yet (~3 day
 *              lag), never 0;
 *   leads / Engage — counts / ₹, 0 on a quiet day.
 *
 * first_dates says when each source has its first record, so a forecast only
 * learns from days the pipeline actually existed.
 */
class ActualsCollector
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?SearchSiteAnalyticsService $search = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{series: array<string, array<string, float|null>>, first_dates: array<string, string|null>, notes: array<string, string>}
     */
    public function collect(string $from, string $to): array
    {
        $dates  = PlanPeriods::dates($from, $to);
        $series = [];
        $first  = [];
        $notes  = [];
        $start  = PlanPeriods::istMidnightUtc($from);
        $end    = PlanPeriods::istMidnightUtc(PlanPeriods::addDays($to, 1));

        foreach ($this->definitions() as $metric => $def) {
            if (! $this->tablesExist($def['tables'])) {
                $series[$metric] = array_fill_keys($dates, null);
                $first[$metric]  = null;
                $notes[$metric]  = 'Not tracked on this deployment (' . implode(', ', $def['tables']) . ' missing).';
                continue;
            }
            $values = array_fill_keys($dates, 0.0);
            try {
                foreach ($this->db->query($def['sql'], array_merge($def['binds'] ?? [], [$start, $end]))->getResultArray() as $row) {
                    if (array_key_exists((string) $row['d'], $values)) {
                        $values[(string) $row['d']] = (float) $row['v'];
                    }
                }
                $first[$metric] = $this->firstDate($def);
            } catch (\Throwable $e) {
                log_message('error', "[BotPlan] actuals {$metric}: " . $e->getMessage());
                $values         = array_fill_keys($dates, null);
                $first[$metric] = null;
                $notes[$metric] = 'Could not be read: ' . mb_substr($e->getMessage(), 0, 160);
            }
            $series[$metric] = $values;
        }

        [$series['search.clicks'], $series['search.impressions'], $first['search.clicks'], $note] = $this->searchSeries($from, $to, $dates);
        $first['search.impressions'] = $first['search.clicks'];
        if ($note !== null) {
            $notes['search.clicks'] = $notes['search.impressions'] = $note;
        }

        return ['series' => $series, 'first_dates' => $first, 'notes' => $notes];
    }

    /**
     * One day, flattened: metric => value (null = no data).
     *
     * @return array<string, float|null>
     */
    public function day(string $date): array
    {
        $out = [];
        foreach ($this->collect($date, $date)['series'] as $metric => $values) {
            $out[$metric] = $values[$date] ?? null;
        }

        return $out;
    }

    // ── sources ─────────────────────────────────────────────────────────────

    /** @return array<string, array{tables: list<string>, sql: string, binds?: list<mixed>, first: string}> */
    private function definitions(): array
    {
        $ist = static fn (string $col): string => "({$col} AT TIME ZONE 'Asia/Kolkata')::date";
        $win = static fn (string $col): string => "{$col} >= ? AND {$col} < ?";

        $defs = [];
        foreach (['blog', 'knowledge_base'] as $type) {
            $defs["content.{$type}.published"] = [
                'tables' => ['reach_content_items'],
                'sql'    => "SELECT {$ist('published_at')}::text AS d, COUNT(*) AS v FROM reach_content_items
                             WHERE content_type = ? AND deleted_at IS NULL AND {$win('published_at')} GROUP BY 1",
                'binds'  => [$type],
                'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_content_items WHERE content_type = '{$type}'",
            ];
        }
        $defs['content.community.published'] = [
            'tables' => ['reach_community_deployments'],
            'sql'    => "SELECT {$ist('deployed_at')}::text AS d, COUNT(*) AS v FROM reach_community_deployments
                         WHERE status = 'succeeded' AND operation = 'publish' AND {$win('deployed_at')} GROUP BY 1",
            'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_community_deployments",
        ];
        foreach (['release_note', 'workflow_guide'] as $type) {
            $defs["content.{$type}.published"] = [
                'tables' => ['reach_structured_content_submissions'],
                'sql'    => "SELECT {$ist('published_at')}::text AS d, COUNT(*) AS v FROM reach_structured_content_submissions
                             WHERE content_type = ? AND status IN ('published','superseded') AND {$win('published_at')} GROUP BY 1",
                'binds'  => [$type],
                'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_structured_content_submissions WHERE content_type = '{$type}'",
            ];
        }
        foreach (['vlog', 'short'] as $format) {
            $defs["video.{$format}.published"] = [
                'tables' => ['reach_video_pipeline_items'],
                'sql'    => "SELECT {$ist('published_at')}::text AS d, COUNT(*) AS v FROM reach_video_pipeline_items
                             WHERE format = ? AND status = 'published' AND {$win('published_at')} GROUP BY 1",
                'binds'  => [$format],
                'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_video_pipeline_items WHERE format = '{$format}'",
            ];
        }
        foreach (array_keys(PlanMetrics::SOCIAL_CHANNELS) as $channel) {
            $defs["social.{$channel}.posted"] = [
                'tables' => ['reach_social_posts'],
                'sql'    => "SELECT {$ist('published_at')}::text AS d, COUNT(*) AS v FROM reach_social_posts
                             WHERE channel = ? AND status = 'posted' AND {$win('published_at')} GROUP BY 1",
                'binds'  => [$channel],
                'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_social_posts WHERE channel = '{$channel}'",
            ];
        }
        $acceptedAt = 'COALESCE(a.accepted_at, a.sent_at, a.delivered_at)';
        $defs['email.accepted'] = [
            'tables' => ['reach_campaign_delivery_attempts', 'reach_campaign_dispatches'],
            'sql'    => "SELECT {$ist($acceptedAt)}::text AS d, COUNT(DISTINCT a.recipient_id) AS v
                         FROM reach_campaign_delivery_attempts a JOIN reach_campaign_dispatches p ON p.id = a.dispatch_id
                         WHERE p.channel = 'email' AND a.status IN ('accepted','sent','delivered','read') AND {$win($acceptedAt)} GROUP BY 1",
            'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_campaign_dispatches WHERE channel = 'email'",
        ];
        $defs['covers.generated'] = [
            'tables' => ['reach_media_gallery_assets'],
            'sql'    => "SELECT {$ist('created_at')}::text AS d, COUNT(*) AS v FROM reach_media_gallery_assets
                         WHERE kind = 'ai_generated' AND {$win('created_at')} GROUP BY 1",
            'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_media_gallery_assets WHERE kind = 'ai_generated'",
        ];
        $defs['seo.checks_run'] = [
            'tables' => ['reach_seo_serp_checks'],
            'sql'    => "SELECT {$ist('checked_at')}::text AS d, COUNT(*) AS v FROM reach_seo_serp_checks
                         WHERE status = 'ok' AND {$win('checked_at')} GROUP BY 1",
            'first'  => "SELECT MIN({$ist('checked_at')})::text AS d FROM reach_seo_serp_checks",
        ];
        $defs['leads.captured'] = [
            'tables' => ['reach_leads'],
            'sql'    => "SELECT {$ist('created_at')}::text AS d, COUNT(*) AS v FROM reach_leads WHERE {$win('created_at')} GROUP BY 1",
            'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_leads",
        ];
        $defs['leads.pushed'] = [
            'tables' => ['reach_leads'],
            'sql'    => "SELECT {$ist('last_push_at')}::text AS d, COUNT(*) AS v FROM reach_leads
                         WHERE engage_push_status = 'pushed' AND {$win('last_push_at')} GROUP BY 1",
            'first'  => "SELECT MIN({$ist('created_at')})::text AS d FROM reach_leads",
        ];
        $defs['engage.conversions'] = [
            'tables' => ['reach_lead_outcomes'],
            'sql'    => "SELECT {$ist('occurred_at')}::text AS d, COUNT(DISTINCT COALESCE(lead_id::text, engage_lead_code, event_id)) AS v
                         FROM reach_lead_outcomes WHERE stage = 'converted' AND {$win('occurred_at')} GROUP BY 1",
            'first'  => "SELECT MIN({$ist('received_at')})::text AS d FROM reach_lead_outcomes",
        ];
        $defs['engage.revenue_inr'] = [
            'tables' => ['reach_lead_outcomes'],
            'sql'    => "SELECT {$ist('occurred_at')}::text AS d, COALESCE(SUM(value_inr), 0) AS v FROM reach_lead_outcomes
                         WHERE (stage = 'payment_confirmed' OR sales_status IN ('payment_confirmed','paid')) AND {$win('occurred_at')} GROUP BY 1",
            'first'  => "SELECT MIN({$ist('received_at')})::text AS d FROM reach_lead_outcomes",
        ];

        return $defs;
    }

    /**
     * @param list<string> $dates
     * @return array{0: array<string, float|null>, 1: array<string, float|null>, 2: string|null, 3: string|null}
     */
    private function searchSeries(string $from, string $to, array $dates): array
    {
        $clicks = array_fill_keys($dates, null);
        $impr   = array_fill_keys($dates, null);
        $svc    = $this->search ?? new SearchSiteAnalyticsService($this->db);
        try {
            if (! $svc->isAvailable()) {
                return [$clicks, $impr, null, 'Search Console tables are not present.'];
            }
            foreach ($svc->daily($from, $to) as $day) {
                if (array_key_exists($day['date'], $clicks)) {
                    $clicks[$day['date']] = (float) $day['clicks'];
                    $impr[$day['date']]   = (float) $day['impressions'];
                }
            }
            $firstRow = $this->db->query('SELECT MIN(metric_date)::text AS d FROM reach_search_site_daily')->getRowArray();
            $first    = $firstRow['d'] ?? null;
            if ($first === null) {
                $f     = $this->db->query('SELECT MIN(metric_date)::text AS d FROM reach_search_site_facts')->getRowArray();
                $first = $f['d'] ?? null;
            }
            $latest = $svc->latestDate();

            return [$clicks, $impr, $first, $latest === null
                ? 'Search Console has not reported any day yet.'
                : ($latest < $to ? "Search Console has reported up to {$latest} (Google publishes with a ~3 day lag)." : null)];
        } catch (\Throwable $e) {
            return [$clicks, $impr, null, 'Could not be read: ' . mb_substr($e->getMessage(), 0, 160)];
        }
    }

    /** @param array<string, mixed> $def */
    private function firstDate(array $def): ?string
    {
        $row = $this->db->query($def['first'])->getRowArray();

        return isset($row['d']) && $row['d'] !== '' ? (string) $row['d'] : null;
    }

    /** @param list<string> $tables */
    private function tablesExist(array $tables): bool
    {
        foreach ($tables as $t) {
            if (! SchemaGuard::hasTable($this->db, $t)) {
                return false;
            }
        }

        return true;
    }
}

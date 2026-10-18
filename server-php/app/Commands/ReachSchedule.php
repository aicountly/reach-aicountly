<?php

namespace App\Commands;

use App\Libraries\Settings\ReachConfig;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;
use App\Libraries\Database\SchemaGuard;

/**
 * `php spark reach:schedule` — housekeeping for the reach_jobs queue.
 *
 *   1. Recover leases that have expired past `lease_expires_at`.
 *   2. Prune completed / dead-letter / cancelled jobs older than N days.
 *   3. Self-heal empty AI model routes (blog drafts cannot run without them).
 *
 * Intended to run every minute via cron:
 *   * * * * * cd /path/to/server-php && php spark reach:schedule
 */
class ReachSchedule extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:schedule';
    protected $description = 'Housekeeping for the reach_jobs queue: recover expired leases and prune old jobs.';
    protected $usage       = 'reach:schedule [--prune-days=14]';

    public function run(array $params): int
    {
        $days = max(1, (int) ($this->sparkOption('prune-days', $params, '14') ?? '14'));

        $svc = Services::jobService();
        $recovered = $svc->recoverExpiredLeases();
        $pruned    = $svc->pruneOlderThanDays($days);

        // Dispatch scheduled Phase 2 jobs once per day (idempotent)
        $this->dispatchDailyJobs($svc);
        $this->dispatchGrowthIntelJobs($svc);

        $aiSeeded = $this->ensureAiCatalogSeeded();

        $out = json_encode([
            'event'     => 'schedule.tick',
            'ts'        => gmdate('c'),
            'recovered' => $recovered,
            'pruned'    => $pruned,
            'prune_days'=> $days,
            'ai_catalog_seeded' => $aiSeeded,
        ], JSON_UNESCAPED_SLASHES);
        CLI::write($out);
        return 0;
    }

    /**
     * Stream H: daily AI-visibility pass and weekly AI Insights
     * (App\Libraries\Intelligence\GrowthIntelSchedule has the timetable).
     */
    private function dispatchGrowthIntelJobs(mixed $svc): void
    {
        try {
            \App\Libraries\Intelligence\GrowthIntelSchedule::dispatch($svc, Database::connect());
        } catch (\Throwable $e) {
            log_message('error', 'reach:schedule growth-intel dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Production once shipped with empty reach_ai_model_routes — drafts failed
     * before any provider call. Re-seed automatically when the table is empty.
     */
    private function ensureAiCatalogSeeded(): bool
    {
        try {
            $db = Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_ai_model_routes')) {
                return false;
            }
            $count = (int) $db->table('reach_ai_model_routes')
                ->where('enabled', true)
                ->where('deleted_at IS NULL', null, false)
                ->countAllResults();
            if ($count > 0 && ! $this->providerKeysChanged($db)) {
                return false;
            }
            $this->call('reach:ai-seed-catalog');
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'reach:schedule AI catalog seed failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * True when a provider's key was added or removed (Settings → AI &
     * Automation, or .env) since the catalog last recorded it, so a key saved
     * in the UI becomes routable on the next tick without a shell.
     */
    private function providerKeysChanged($db): bool
    {
        $keys = ['openai' => 'ai.openai.api_key', 'gemini' => 'ai.gemini.api_key', 'perplexity' => 'ai.perplexity.api_key'];
        foreach ($db->table('reach_ai_providers')->select('provider_key, configuration_status')->whereIn('provider_key', array_keys($keys))->get()->getResultArray() as $row) {
            $hasKey = ReachConfig::secret($keys[$row['provider_key']]) !== null;
            if ($hasKey !== ($row['configuration_status'] === 'configured')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enqueue the scheduled jobs for this tick.
     *
     * reach:schedule runs every minute, so anything gated only on the hour
     * fires sixty times in that hour. JobService's idempotency key alone does
     * not stop that either: it only de-duplicates while the first job is still
     * in flight — once it completes, the next enqueue retires the key and
     * queues a fresh copy. Every planned job therefore carries a key scoped to
     * its period (day or hour) and enqueueOnce() skips it when any job with
     * that key — live, finished or retired — already exists.
     */
    private function dispatchDailyJobs(mixed $svc): void
    {
        foreach (self::plannedJobs(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) as $job) {
            try {
                $this->enqueueOnce($svc, $job['type'], $job['payload'], $job['opts']);
            } catch (\Throwable $e) {
                log_message('error', 'reach:schedule could not enqueue ' . $job['type'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * What this tick should enqueue. Pure, so the timetable is unit-tested.
     * Hours are UTC (the server clock, App::$appTimezone) unless marked IST.
     *
     * @return list<array{type:string, payload:array<string, mixed>, opts:array<string, mixed>}>
     */
    public static function plannedJobs(\DateTimeImmutable $now): array
    {
        $utc      = $now->setTimezone(new \DateTimeZone('UTC'));
        $today    = $utc->format('Y-m-d');
        $hour     = (int) $utc->format('H');
        $minute   = (int) $utc->format('i');
        $hourKey  = $utc->format('Y-m-d-H');
        $ist      = $now->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $istDate  = $ist->format('Y-m-d');
        $istHour  = (int) $ist->format('H');

        $jobs = [];
        $add  = static function (string $type, array $payload, string $key, array $opts = []) use (&$jobs): void {
            $jobs[] = ['type' => $type, 'payload' => $payload, 'opts' => $opts + ['idempotency_key' => $key]];
        };

        // 08:00 — daily approval digest
        if ($hour === 8) {
            $add('reach.daily_approval_digest', ['date' => $today], "approval-digest-{$today}", ['queue' => 'notifications']);
        }

        // 07:00 — generate tomorrow's marketing pack
        if ($hour === 7) {
            $tomorrow = $utc->modify('+1 day')->format('Y-m-d');
            $add('reach.daily_marketing_pack', ['date' => $tomorrow], "marketing-pack-{$tomorrow}");
        }

        // Every hour: release articles parked for want of a cover. Covers
        // arrive when an operator uploads them, not on a schedule, so an
        // article parked at 09:10 should not wait a day because its cover
        // landed at 09:15. Idempotent per article per day.
        $add('reach.blog_cover_sweep', ['limit' => 25], "blog-cover-sweep-{$hourKey}", ['queue' => 'blog']);

        // Stream C — every 5 minutes: carry Claude-routine blog/KB submissions
        // forward (re-vet with backoff, hourly cover retry, publish, verify).
        if ($minute % 5 === 0) {
            $add('reach.routine_content_sweep', ['limit' => 10], 'routine-content-sweep-' . $utc->format('YmdHi'), ['queue' => 'default']);
        }

        // Every hour: due-date reminders, overdue escalation, schedule readiness
        $add('reach.content_due_date_reminder', [], "content-due-date-reminder-{$hourKey}", ['queue' => 'notifications']);
        $add('reach.content_overdue_escalation', [], "content-overdue-escalation-{$hourKey}", ['queue' => 'notifications']);
        $add('reach.content_schedule_readiness', [], "content-schedule-readiness-{$hourKey}");

        // Every 15 minutes: release notes / workflow guides waiting on vetting
        // (Gemini unavailable) or on a publish that has not gone live yet.
        if ($minute % 15 === 0) {
            $add('reach.structured_content_sweep', ['limit' => 20], 'structured-content-sweep-' . $utc->format('YmdHi'), ['queue' => 'publishing']);
        }

        // 03:00 — refresh detection
        if ($hour === 3) {
            $add('reach.content_refresh_detection', [], "content-refresh-detection-{$today}");
        }

        // 06:00 — sync repo content-base into topic candidates (idempotent per day)
        if ($hour === 6) {
            $add('reach.content_base_sync', ['date' => $today], "content-base-sync-{$today}");
        }

        // 09:00 — superadmin cover-gallery deficit reminder (skips when no deficit)
        if ($hour === 9) {
            $add('reach.gallery_deficit_alert', ['date' => $today], "gallery-deficit-{$today}", ['queue' => 'notifications']);
        }

        // 04:00 — pull Search Console facts. Must run before the SEO snapshot at
        // 05:00, which aggregates exactly the rows this job writes.
        if ($hour === 4) {
            $add('reach.search_console_ingest', ['tenant_id' => 1], "search-console-ingest-{$today}");
        }

        // Every hour: video pipeline sweep — re-vet scripts left pending, retry
        // blocked items, re-queue lost steps (queue `video`).
        $add('reach.video_pipeline_sweep', ['date' => $today, 'hour' => $hour], "video-pipeline-sweep-{$hourKey}", ['queue' => 'video']);

        // 05:00 — SEO rank + backlink snapshots of the freshest ingested day
        // (Search Console publishes with a ~3 day lag).
        if ($hour === 5) {
            $add('reach.seo_snapshot', ['date' => null], "seo-snapshot-{$today}");
        }

        // 06:00 IST — SEO Tracker SERP checks (DataForSEO; no-op without credentials).
        if ($istHour === 6) {
            $add('reach.seo_tracker', ['date' => $istDate], "seo-tracker-{$istDate}");
        }

        // 08:00 IST — daily analytics report email (idempotent per IST date).
        if ($istHour === 8) {
            $add('reach.daily_analytics_report', ['date' => $istDate], "daily-report-{$istDate}", ['queue' => 'notifications']);
        }

        return $jobs;
    }

    /**
     * Enqueue unless a job with this key already exists in any state,
     * including one whose key JobService retired (":retired:<id>" suffix).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $opts must carry idempotency_key
     */
    private function enqueueOnce(mixed $svc, string $type, array $payload, array $opts): ?int
    {
        $key = (string) $opts['idempotency_key'];
        $db  = Database::connect();

        $exists = $db->query(
            'SELECT 1 FROM reach_jobs WHERE idempotency_key = ? OR LEFT(idempotency_key, ?) = ? LIMIT 1',
            [$key, strlen($key) + 9, $key . ':retired:']
        )->getRowArray();
        if ($exists) {
            return null;
        }

        return $svc->enqueue($type, $payload, $opts);
    }
}

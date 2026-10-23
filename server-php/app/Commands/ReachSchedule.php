<?php

namespace App\Commands;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Support\DisplayTime;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;
use App\Libraries\Database\SchemaGuard;

/**
 * `php spark reach:schedule` — housekeeping for the reach_jobs queue.
 *
 *   1. Recover leases that have expired past `lease_expires_at`.
 *   2. Prune finished jobs: completed / skipped / cancelled after
 *      --prune-days (default 7), dead_letter / failed after
 *      --prune-failed-days (default 14); 5 000 rows per tick at most.
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
    protected $usage       = 'reach:schedule [--prune-days=7] [--prune-failed-days=14]';

    public function run(array $params): int
    {
        // Completed jobs are mostly the scheduler's own sweeps (~60 an hour),
        // so a week is plenty; failures are kept longer for diagnosis.
        $days       = max(1, (int) ($this->sparkOption('prune-days', $params, '7') ?? '7'));
        $failedDays = max(1, (int) ($this->sparkOption('prune-failed-days', $params, '14') ?? '14'));

        $svc = Services::jobService();
        $recovered = 0;
        $pruned    = 0;
        // Housekeeping must never stop the timetable below from running.
        try {
            $recovered = $svc->recoverExpiredLeases();
        } catch (\Throwable $e) {
            log_message('error', 'reach:schedule lease recovery failed: ' . $e->getMessage());
        }
        try {
            $pruned = $svc->pruneOlderThanDays($days, $failedDays);
        } catch (\Throwable $e) {
            log_message('error', 'reach:schedule prune failed: ' . $e->getMessage());
        }

        // Dispatch scheduled Phase 2 jobs once per day (idempotent)
        $this->dispatchDailyJobs($svc);
        $this->dispatchGrowthIntelJobs($svc);

        $aiSeeded = $this->ensureAiCatalogSeeded();

        $out = json_encode([
            'event'     => 'schedule.tick',
            'ts'        => gmdate('c'),
            'ts_ist'    => DisplayTime::format(time()),
            'recovered' => $recovered,
            'pruned'    => $pruned,
            'prune_days'=> $days,
            'prune_failed_days' => $failedDays,
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
     * Hours are UTC (the server clock, App::$appTimezone) unless marked IST;
     * each comment gives both (IST = UTC + 05:30). docs/operations/TIMEZONES.md
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
            $jobs[] = ['type' => $type, 'payload' => $payload, 'opts' => $opts + ['idempotency_key' => $key, 'enqueued_actor_type' => 'system']];
        };

        // 08:00 UTC (13:30 IST) — daily approval digest
        if ($hour === 8) {
            $add('reach.daily_approval_digest', ['date' => $today], "approval-digest-{$today}", ['queue' => 'notifications']);
        }

        // 07:00 UTC (12:30 IST) — generate tomorrow's marketing pack
        if ($hour === 7) {
            $tomorrow = $utc->modify('+1 day')->format('Y-m-d');
            $add('reach.daily_marketing_pack', ['date' => $tomorrow], "marketing-pack-{$tomorrow}");
        }

        // Every hour: stock the Cover Gallery with the covers the upcoming blog
        // index is missing (GPT Image, Aicountly-branded; capped by
        // covers.auto.daily_limit, idempotent per index entry). No human
        // uploads covers any more.
        $add('reach.cover_gallery_autofill', ['limit' => 5], "cover-gallery-autofill-{$hourKey}", ['queue' => 'blog']);

        // Every hour: release articles parked for want of a cover. Covers land
        // through the day (the autofill above, an occasional upload), so an
        // article parked at 09:10 should not wait a day because its cover
        // arrived at 09:15. Idempotent per article per day, re-armed when a
        // new gallery cover appears.
        $add('reach.blog_cover_sweep', ['limit' => 25], "blog-cover-sweep-{$hourKey}", ['queue' => 'blog']);

        // Stream C — every 5 minutes: carry Claude-routine blog/KB submissions
        // forward (re-vet with backoff, hourly cover retry, publish, verify).
        if ($minute % 5 === 0) {
            $add('reach.routine_content_sweep', ['limit' => 10], 'routine-content-sweep-' . $utc->format('YmdHi'), ['queue' => 'default']);
        }

        // Every 5 minutes: confirm social-api.ai posts that are still `publishing`
        // (published / failed / 30-minute timeout). Idempotent per 5-minute slot.
        if ($minute % 5 === 0) {
            $add('reach.social_post_poll', ['limit' => 25], 'social-post-poll-' . $utc->format('YmdHi'), ['queue' => 'distribution']);
        }

        // Stream a — every 10 minutes: the social inbox bot reads new comments / DMs
        // and answers, lodges complaints in Flow or records leads. Idempotent per slot.
        if ($minute % 10 === 0) {
            $add('reach.social_inbox_poll', ['limit' => 15], 'social-inbox-poll-' . $utc->format('YmdHi'), ['queue' => 'distribution']);
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

        // 01:00 UTC (06:30 IST) — content quality safety net: re-vet what went live in the
        // last 48 h; a vetting-model reject takes it down and queues a
        // same-slug rewrite (ContentRevetService). Before the 02:30 report.
        if ($hour === 1) {
            $add('reach.content_quality_sweep', ['hours' => 48], "content-quality-sweep-{$today}", ['queue' => 'publishing']);
        }

        // Every 15 minutes: leads Engage has not accepted yet (retry_scheduled)
        // are retried with backoff; nobody has to press "Push".
        if ($minute % 15 === 0) {
            $add('reach.engage_push_retry_sweep', ['limit' => 25], 'engage-push-retry-' . $utc->format('YmdHi'), ['queue' => 'default']);
        }

        // 03:00 UTC (08:30 IST) — refresh detection
        if ($hour === 3) {
            $add('reach.content_refresh_detection', [], "content-refresh-detection-{$today}");
        }

        // Every hour: content base — import git seeds, release stuck roadmap
        // topics, reconcile topic statuses from the pipeline (idempotent).
        $add('reach.content_base_sync', ['date' => $today], "content-base-sync-{$hourKey}");

        // 01:00 IST (19:30 UTC) — rescore the roadmap for today's IST date,
        // unless the 00:30 IST cron already completed a run (RoadmapRefreshJob).
        if ($istHour === 1) {
            $add('reach.roadmap_refresh', ['date' => $istDate], "roadmap-refresh-{$istDate}");
        }

        // 09:00 UTC (14:30 IST) — informational email only if cover generation keeps failing
        // (the deficit itself is a line in the daily report; nobody is asked to upload)
        if ($hour === 9) {
            $add('reach.gallery_deficit_alert', ['date' => $today], "gallery-deficit-{$today}", ['queue' => 'notifications']);
        }

        // 04:00 UTC (09:30 IST) — pull Search Console facts. Must run before the SEO
        // snapshot at 05:00 UTC, which aggregates exactly the rows this job writes.
        if ($hour === 4) {
            $add('reach.search_console_ingest', ['tenant_id' => 1], "search-console-ingest-{$today}");
        }

        // Every hour: video pipeline sweep — re-vet scripts left pending, retry
        // blocked items, re-queue lost steps (queue `video`).
        $add('reach.video_pipeline_sweep', ['date' => $today, 'hour' => $hour], "video-pipeline-sweep-{$hourKey}", ['queue' => 'video']);

        // 05:00 UTC (10:30 IST) — SEO rank + backlink snapshots of the freshest ingested day
        // (Search Console publishes with a ~3 day lag).
        if ($hour === 5) {
            $add('reach.seo_snapshot', ['date' => null], "seo-snapshot-{$today}");
        }

        // 06:00 IST (00:30 UTC) — SEO Tracker SERP checks (DataForSEO; no-op without credentials).
        if ($istHour === 6) {
            $add('reach.seo_tracker', ['date' => $istDate], "seo-tracker-{$istDate}");
        }

        // 08:00 IST (02:30 UTC) — daily analytics report email (idempotent per IST date).
        if ($istHour === 8) {
            $add('reach.daily_analytics_report', ['date' => $istDate], "daily-report-{$istDate}", ['queue' => 'notifications']);
        }

        // Bot Plan & Performance — 01:00 IST: snapshot yesterday's actuals, review the
        // last 7 days, self-tune, regenerate plans (once per IST date). Every other
        // hour: regenerate plans only (no new version when nothing changed).
        if ($istHour === 1) {
            $add('reach.bot_plan', ['mode' => 'daily', 'date' => $istDate], "bot-plan-daily-{$istDate}");
        } else {
            $add('reach.bot_plan', ['mode' => 'refresh', 'date' => $istDate], 'bot-plan-refresh-' . $ist->format('Y-m-d-H'));
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
        return $svc->enqueueOnce($type, $payload, $opts);
    }
}

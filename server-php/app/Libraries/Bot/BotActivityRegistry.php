<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

/**
 * Every scheduled / automated bot activity in Reach, in one place, so the
 * owner can see what runs when and run any of it now (Bot → Activities).
 *
 * Kinds:
 *   job     — a reach_jobs job that `reach:schedule` (or GrowthIntelSchedule)
 *             enqueues. "Run now" enqueues the SAME job type on the SAME queue
 *             with the payload the scheduler would build at this moment.
 *   command — a spark command a cron line runs. "Run now" queues a
 *             `reach.bot_activity_command` job whose handler runs exactly that
 *             command (`php spark <command> <args>`) as a child process —
 *             the cron's own invocation, with the documented manual flags.
 *   routine — a Claude Routine (claude.ai → Routines) with a prompt file under
 *             docs/automation/routines/. "Run now" fires the reach-command
 *             routine, asking it to carry out that prompt once now.
 *   worker  — the queue workers; listed for completeness, never run from here.
 *
 * Schedules are cron expressions in UTC (the server clock). The tests pin
 * the `job` entries to ReachSchedule::plannedJobs / GrowthIntelSchedule, so
 * this list cannot quietly drift from what actually runs.
 */
final class BotActivityRegistry
{
    public const COMMAND_JOB_TYPE = 'reach.bot_activity_command';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $utcDay   = static fn (\DateTimeImmutable $n): string => $n->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $istDay   = static fn (\DateTimeImmutable $n): string => $n->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d');

        $entries = [
            // ── Content & publishing (reach:schedule) ─────────────────────────
            'routine_content_sweep' => [
                'name' => 'Routine articles sweep', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Carries Claude-routine blog and KB submissions forward: re-vets with Gemini after an outage, retries covers, publishes and verifies.',
                'cron' => '*/5 * * * *', 'schedule' => 'Every 5 minutes',
                'job_type' => 'reach.routine_content_sweep', 'queue' => 'default',
                'payload' => static fn (): array => ['limit' => 10],
                'cost' => 'Gemini vetting calls and GPT Image covers only for submissions waiting on them.',
                'link' => '/blog-command-centre/verification/routine',
            ],
            'cover_gallery_autofill' => [
                'name' => 'Cover gallery autofill', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Generates AICOUNTLY-branded GPT Image covers for upcoming articles no gallery cover fits, then releases articles parked for a cover.',
                'cron' => '0 * * * *', 'schedule' => 'Hourly (at :00 UTC, :30 IST)',
                'job_type' => 'reach.cover_gallery_autofill', 'queue' => 'blog',
                'payload' => static fn (): array => ['limit' => 5],
                'cost' => 'GPT Image calls, capped by covers.auto.daily_limit.',
                'link' => '/quality/gallery',
            ],
            'social_inbox_poll' => [
                'name' => 'Social inbox', 'group' => 'Social & video', 'kind' => 'job',
                'description' => 'Reads new comments and DMs via social-api.ai, answers with a vetted reply, lodges complaints in Flow and records sales leads.',
                'cron' => '*/10 * * * *', 'schedule' => 'Every 10 minutes',
                'job_type' => 'reach.social_inbox_poll', 'queue' => 'distribution',
                'payload' => static fn (): array => ['limit' => 15],
                'cost' => 'Gemini classify / draft / vet calls per new message; replies capped per day.',
                'link' => '/social/inbox',
            ],
            'bot_plan' => [
                'name' => 'Bot plan & self-tuning', 'group' => 'Search, SEO & reports', 'kind' => 'job',
                'description' => 'Regenerates the plan for every period; at 01:00 IST also snapshots actuals, reviews the last 7 days and self-tunes within its bounds.',
                'cron' => '30 * * * *', 'schedule' => 'Hourly (at :00 IST); full review daily 01:00 IST (19:30 UTC)',
                'job_type' => 'reach.bot_plan', 'queue' => 'default',
                'payload' => static fn (\DateTimeImmutable $n): array => [
                    'mode' => (int) $n->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('H') === 1 ? 'daily' : 'refresh',
                    'date' => $istDay($n),
                ],
                'cost' => 'No provider calls (database only).',
                'link' => '/bot/plan',
            ],
            'structured_content_sweep' => [
                'name' => 'Release notes & guides sweep', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Release notes and workflow guides waiting on vetting, or on a publish that has not gone live yet.',
                'cron' => '*/15 * * * *', 'schedule' => 'Every 15 minutes',
                'job_type' => 'reach.structured_content_sweep', 'queue' => 'publishing',
                'payload' => static fn (): array => ['limit' => 20],
                'cost' => 'Gemini vetting calls only for items waiting on them.',
                'link' => '/admin/jobs',
            ],
            'blog_cover_sweep' => [
                'name' => 'Blog cover sweep', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Releases articles parked for want of a cover image.',
                'cron' => '0 * * * *', 'schedule' => 'Hourly',
                'job_type' => 'reach.blog_cover_sweep', 'queue' => 'blog',
                'payload' => static fn (): array => ['limit' => 25],
                'link' => '/blog-command-centre/publishing/ready',
            ],
            'content_schedule_readiness' => [
                'name' => 'Schedule readiness check', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Checks scheduled content is ready to go out on time.',
                'cron' => '0 * * * *', 'schedule' => 'Hourly',
                'job_type' => 'reach.content_schedule_readiness', 'queue' => 'default',
                'payload' => static fn (): array => [],
                'link' => '/blog-command-centre/publishing/scheduled',
            ],
            'content_due_date_reminder' => [
                'name' => 'Due-date reminders', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Notifications for content approaching its due date.',
                'cron' => '0 * * * *', 'schedule' => 'Hourly',
                'job_type' => 'reach.content_due_date_reminder', 'queue' => 'notifications',
                'payload' => static fn (): array => [],
                'cost' => 'May send notification emails.',
                'link' => '/admin/jobs',
            ],
            'content_overdue_escalation' => [
                'name' => 'Overdue escalation', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Escalates content past its due date.',
                'cron' => '0 * * * *', 'schedule' => 'Hourly',
                'job_type' => 'reach.content_overdue_escalation', 'queue' => 'notifications',
                'payload' => static fn (): array => [],
                'cost' => 'May send notification emails.',
                'link' => '/admin/jobs',
            ],
            'content_refresh_detection' => [
                'name' => 'Content refresh detection', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Finds published content that needs a refresh.',
                'cron' => '0 3 * * *', 'schedule' => 'Daily 08:30 IST (03:00 UTC)',
                'job_type' => 'reach.content_refresh_detection', 'queue' => 'default',
                'payload' => static fn (): array => [],
                'link' => '/blog-command-centre/roadmap/refresh',
            ],
            'content_base_sync' => [
                'name' => 'Content-base sync', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Ingests server-php/content-base (blog index, KB index, question seeds) into topic candidates.',
                'cron' => '0 6 * * *', 'schedule' => 'Daily 11:30 IST (06:00 UTC)',
                'job_type' => 'reach.content_base_sync', 'queue' => 'default',
                'payload' => static fn (\DateTimeImmutable $n): array => ['date' => $utcDay($n)],
                'link' => '/quality/content-base',
            ],
            'daily_marketing_pack' => [
                'name' => 'Daily marketing pack', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Builds tomorrow\'s marketing pack.',
                'cron' => '0 7 * * *', 'schedule' => 'Daily 12:30 IST (07:00 UTC)',
                'job_type' => 'reach.daily_marketing_pack', 'queue' => 'default',
                'payload' => static fn (\DateTimeImmutable $n): array => ['date' => $n->setTimezone(new \DateTimeZone('UTC'))->modify('+1 day')->format('Y-m-d')],
                'link' => '/content/daily-pack',
            ],
            'gallery_deficit_alert' => [
                'name' => 'Cover-gallery deficit alert', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Informational email only when cover generation keeps failing; covers themselves are generated automatically.',
                'cron' => '0 9 * * *', 'schedule' => 'Daily 14:30 IST (09:00 UTC)',
                'job_type' => 'reach.gallery_deficit_alert', 'queue' => 'notifications',
                'payload' => static fn (\DateTimeImmutable $n): array => ['date' => $utcDay($n)],
                'cost' => 'May send an email.',
                'link' => '/quality/gallery',
            ],
            'daily_approval_digest' => [
                'name' => 'Approval digest', 'group' => 'Content & publishing', 'kind' => 'job',
                'description' => 'Informational digest of anything held for a person (high-risk holds).',
                'cron' => '0 8 * * *', 'schedule' => 'Daily 13:30 IST (08:00 UTC)',
                'job_type' => 'reach.daily_approval_digest', 'queue' => 'notifications',
                'payload' => static fn (\DateTimeImmutable $n): array => ['date' => $utcDay($n)],
                'cost' => 'May send an email.',
                'link' => '/approvals',
            ],

            // ── Social & video ───────────────────────────────────────────────
            'social_post_poll' => [
                'name' => 'Social post status poll', 'group' => 'Social & video', 'kind' => 'job',
                'description' => 'Confirms social-api.ai posts still `publishing` (published / failed / 30-minute timeout).',
                'cron' => '*/5 * * * *', 'schedule' => 'Every 5 minutes',
                'job_type' => 'reach.social_post_poll', 'queue' => 'distribution',
                'payload' => static fn (): array => ['limit' => 25],
                'link' => '/social/queue',
            ],
            'video_pipeline_sweep' => [
                'name' => 'Video pipeline sweep', 'group' => 'Social & video', 'kind' => 'job',
                'description' => 'Re-vets video scripts left pending, retries blocked videos, re-queues lost steps.',
                'cron' => '0 * * * *', 'schedule' => 'Hourly',
                'job_type' => 'reach.video_pipeline_sweep', 'queue' => 'video',
                'payload' => static fn (\DateTimeImmutable $n): array => ['date' => $utcDay($n), 'hour' => (int) $n->setTimezone(new \DateTimeZone('UTC'))->format('G')],
                'cost' => 'May call Gemini, OpenAI TTS / GPT Image and Shotstack for items it moves forward.',
                'link' => '/video',
            ],

            // ── Search, SEO & reports ────────────────────────────────────────
            'search_console_ingest' => [
                'name' => 'Search Console ingest', 'group' => 'Search, SEO & reports', 'kind' => 'job',
                'description' => 'Pulls Search Console facts for the incremental window and one slice of any active backfill.',
                'cron' => '0 4 * * *', 'schedule' => 'Daily 09:30 IST (04:00 UTC)',
                'job_type' => 'reach.search_console_ingest', 'queue' => 'default',
                'payload' => static fn (): array => ['tenant_id' => 1],
                'cost' => 'Google Search Console API (free quota).',
                'link' => '/intelligence/search',
            ],
            'seo_snapshot' => [
                'name' => 'SEO snapshot', 'group' => 'Search, SEO & reports', 'kind' => 'job',
                'description' => 'Tracked-keyword ranks of the freshest ingested Search Console day plus a backlink snapshot.',
                'cron' => '0 5 * * *', 'schedule' => 'Daily 10:30 IST (05:00 UTC)',
                'job_type' => 'reach.seo_snapshot', 'queue' => 'default',
                'payload' => static fn (): array => ['date' => null],
                'link' => '/seo-centre/overview',
            ],
            'seo_tracker' => [
                'name' => 'SEO Tracker (SERP checks)', 'group' => 'Search, SEO & reports', 'kind' => 'job',
                'description' => 'DataForSEO live SERP checks of the due tracked keywords, capped per day; a no-op without credentials.',
                'cron' => '30 0 * * *', 'schedule' => 'Daily 06:00 IST (00:30 UTC)',
                'job_type' => 'reach.seo_tracker', 'queue' => 'default',
                'payload' => static fn (\DateTimeImmutable $n): array => ['date' => $istDay($n)],
                'cost' => 'Paid DataForSEO SERP calls (one per keyword, capped by seo.tracker.max_keywords_per_day; keywords already checked today are not re-checked).',
                'setting' => 'seo.tracker.enabled',
                'link' => '/seo-centre/tracker',
            ],
            'daily_analytics_report' => [
                'name' => 'Daily analytics report', 'group' => 'Search, SEO & reports', 'kind' => 'job',
                'description' => 'Builds today\'s report and emails it to reports.daily.recipients; a date already sent is never re-sent by this job.',
                'cron' => '30 2 * * *', 'schedule' => 'Daily 08:00 IST (02:30 UTC)',
                'job_type' => 'reach.daily_analytics_report', 'queue' => 'notifications',
                'payload' => static fn (\DateTimeImmutable $n): array => ['date' => $istDay($n)],
                'cost' => 'Sends the report email (once per date).',
                'setting' => 'reports.daily.enabled',
                'link' => '/reports',
            ],
            'ai_visibility_daily' => [
                'name' => 'AI visibility pass', 'group' => 'Search, SEO & reports', 'kind' => 'job',
                'description' => 'Asks each active visibility prompt to every configured assistant and records whether AICOUNTLY is mentioned.',
                'cron' => '30 23 * * *', 'schedule' => 'Daily 05:00 IST (23:30 UTC, previous day)',
                'job_type' => 'reach.ai_visibility_daily', 'queue' => 'default', 'max_attempts' => 1,
                'payload' => static fn (\DateTimeImmutable $n, ?int $userId = null): array => ['trigger' => 'manual', 'date' => $istDay($n), 'user_id' => $userId],
                'cost' => 'Paid AI calls: up to visibility.daily_prompt_limit prompts × each configured assistant (Gemini grounding is billed separately).',
                'setting' => 'visibility.enabled',
                'link' => '/intelligence/visibility/runs',
            ],
            'ai_insights_weekly' => [
                'name' => 'AI Insights report', 'group' => 'Search, SEO & reports', 'kind' => 'job',
                'description' => 'The weekly AI Insights report from search, traffic and visibility data.',
                'cron' => '30 10 * * 0', 'schedule' => 'Sundays 16:00 IST (10:30 UTC)',
                'job_type' => 'reach.ai_insights_run', 'queue' => 'default', 'max_attempts' => 1,
                'payload' => static fn (\DateTimeImmutable $n, ?int $userId = null): array => ['trigger' => 'manual', 'date' => $istDay($n), 'user_id' => $userId],
                'cost' => 'One paid Gemini call with the AI Insights model.',
                'setting' => 'insights.weekly.enabled',
                'link' => '/intelligence/insights',
            ],

            // ── Cron commands ───────────────────────────────────────────────
            'reach_schedule' => [
                'name' => 'Scheduler tick (reach:schedule)', 'group' => 'Cron commands', 'kind' => 'command',
                'description' => 'Recovers expired job leases, prunes old jobs, enqueues whatever is due this minute (never twice per period) and self-heals empty AI routes.',
                'cron' => '* * * * *', 'schedule' => 'Every minute',
                'command' => 'reach:schedule', 'args' => [],
                'log' => 'schedule.log', 'link' => '/admin/jobs',
            ],
            'blog_dispatch' => [
                'name' => 'Blog dispatch', 'group' => 'Cron commands', 'kind' => 'command',
                'description' => 'Turns eligible blog work blocks into jobs (API blog route). Run now passes --force, so it also runs outside the 21:00–09:00 IST window.',
                'cron' => '30 15,17,19,21,23,1 * * *', 'schedule' => 'Every 2 hours 21:00–07:00 IST (15:30–01:30 UTC)',
                'command' => 'reach:blog-dispatch', 'args' => ['--force'],
                'cost' => 'Queues blog drafting work, which calls the configured AI providers.',
                'log' => 'blog-dispatch.log', 'link' => '/blog-command-centre/operations/queue',
            ],
            'blog_discover_topics' => [
                'name' => 'Blog topic discovery', 'group' => 'Cron commands', 'kind' => 'command',
                'description' => 'Daily topic discovery into reach_topic_candidates (before the optimiser).',
                'cron' => '45 18 * * *', 'schedule' => 'Daily 00:15 IST (18:45 UTC)',
                'command' => 'reach:blog-discover-topics', 'args' => ['--limit=10'],
                'log' => 'blog-discover.log', 'link' => '/blog-command-centre/roadmap/candidates',
            ],
            'blog_optimize_roadmap' => [
                'name' => 'Roadmap optimiser', 'group' => 'Cron commands', 'kind' => 'command',
                'description' => 'Scores topic candidates and plans the day\'s work blocks. Run now passes --force (its preferred window is 00:00–00:59 IST).',
                'cron' => '0 19 * * *', 'schedule' => 'Daily 00:30 IST (19:00 UTC)',
                'command' => 'reach:blog-optimize-roadmap', 'args' => ['--force'],
                'log' => 'blog-optimizer.log', 'link' => '/blog-command-centre/roadmap/optimizer',
            ],
            'community_schedule' => [
                'name' => 'Community housekeeping', 'group' => 'Cron commands', 'kind' => 'command',
                'description' => 'Publishes due scheduled answers, retries deployments, re-vets routine Q&A left pending, syncs and reconciles with aicountly.com.',
                'cron' => '*/5 * * * *', 'schedule' => 'Every 5 minutes',
                'command' => 'community:schedule', 'args' => [],
                'cost' => 'Gemini vetting calls only for submissions waiting on them.',
                'log' => 'community-schedule.log', 'link' => '/community/automated',
            ],
            'community_agents_run' => [
                'name' => 'Community intake agents', 'group' => 'Cron commands', 'kind' => 'command',
                'description' => 'Seed curation, intake screening and categorisation (window-gated 09:00–19:00 IST and daily-capped inside the agents). Does not draft answers.',
                'cron' => '*/30 * * * *', 'schedule' => 'Every 30 minutes',
                'command' => 'community:agents-run', 'args' => [],
                'log' => 'community-agents.log', 'link' => '/community/agents',
            ],
            'console_audit_deliver' => [
                'name' => 'Console audit delivery', 'group' => 'Cron commands', 'kind' => 'command',
                'description' => 'Delivers queued audit events to Console\'s central audit log.',
                'cron' => '*/5 * * * *', 'schedule' => 'Every 5 minutes',
                'command' => 'reach:console-audit-deliver', 'args' => [],
                'link' => '/admin/audit-logs',
            ],

            // ── Queue workers (not runnable from here) ──────────────────────
            'worker_main' => [
                'name' => 'Queue worker', 'group' => 'Queue workers', 'kind' => 'worker',
                'description' => 'Drains the default, blog, publishing, community, notifications and distribution queues (reach:work --once).',
                'cron' => '* * * * *', 'schedule' => 'Every minute',
                'not_runnable' => 'The cron worker already runs every minute; a second worker started from here would compete with it. Jobs queued with Run now are picked up by it within a minute.',
                'log' => 'worker.log', 'link' => '/admin/jobs',
            ],
            'worker_video' => [
                'name' => 'Video worker', 'group' => 'Queue workers', 'kind' => 'worker',
                'description' => 'Drains the video queue, at most 3 short steps per run (reach:work --queue=video).',
                'cron' => '* * * * *', 'schedule' => 'Every minute',
                'not_runnable' => 'The video worker already runs every minute from cron.',
                'log' => 'worker-video.log', 'link' => '/video',
            ],
        ];

        foreach (self::routines() as $key => $routine) {
            $entries[$key] = $routine;
        }

        return $entries;
    }

    /**
     * The Claude Routines (docs/automation/CLAUDE_ROUTINES.md). They run in
     * claude.ai on their own cron; Reach cannot see those runs. Run now goes
     * through the reach-command routine.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function routines(): array
    {
        $r = static fn (string $name, string $cron, string $schedule, string $description, string $link, string $cost = ''): array => [
            'name' => $name, 'group' => 'Claude routines', 'kind' => 'routine',
            'description' => $description, 'cron' => $cron, 'schedule' => $schedule,
            'routine' => $name, 'prompt_file' => "docs/automation/routines/{$name}.md",
            'cost' => trim('One Claude routine session (via reach-command; counts toward its 30 fires/hour and the account\'s 100/hour). ' . $cost),
            'link' => $link,
        ];

        return [
            'routine_reach_blog' => $r('reach-blog', '30 3-14 * * *', 'Hourly 09:00–20:00 IST (03:30–14:30 UTC)', 'Writes one blog article per run (stops at the daily blog quota); Gemini vets, Reach publishes.', '/blog-command-centre/verification/routine', 'Reach\'s daily blog quota still applies.'),
            'routine_reach_kb' => $r('reach-kb', '30 4,10 * * *', 'Daily 10:00 and 16:00 IST (04:30, 10:30 UTC)', 'Writes up to 5 help-centre articles per run.', '/blog-command-centre/publishing', 'Reach\'s daily KB quota still applies.'),
            'routine_reach_community_qa' => $r('reach-community-qa', '30 2-14/2 * * *', 'Every 2 h 08:00–20:00 IST (02:30–14:30 UTC)', 'Answers open member questions first, then up to 3 desk FAQs.', '/community/automated', 'Reach\'s daily community quota still applies.'),
            'routine_reach_video' => $r('reach-video', '30 1 * * *', 'Daily 07:00 IST (01:30 UTC)', 'Writes one vlog and one Short script; Reach vets, voices, renders and publishes them.', '/video', 'Reach\'s daily video quota still applies; Shotstack / OpenAI costs per video.'),
            'routine_reach_release_notes' => $r('reach-release-notes', '30 15 * * *', 'Daily 21:00 IST (15:30 UTC)', 'One release note per product with user-visible changes since its last note.', '/admin/jobs'),
            'routine_reach_workflow_guides' => $r('reach-workflow-guides', '30 0 * * 1', 'Mondays 06:00 IST (00:30 UTC)', 'Refreshes / adds launch-app workflow guides.', '/admin/jobs'),
            'routine_reach_growth_strategy' => $r('reach-growth-strategy', '30 12 * * 0', 'Sundays 18:00 IST (12:30 UTC)', 'Weekly strategy memo from AI Insights and AI visibility.', '/intelligence/insights'),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Spark commands a manual run may start — the allow-list the command job enforces. */
    public static function commandFor(string $key): ?array
    {
        $entry = self::get($key);
        if ($entry === null || ($entry['kind'] ?? null) !== 'command') {
            return null;
        }
        return ['command' => (string) $entry['command'], 'args' => array_values((array) ($entry['args'] ?? []))];
    }
}

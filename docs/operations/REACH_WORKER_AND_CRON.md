# REACH Worker and Cron Guide

This document targets cPanel-style shared-hosting environments where we
cannot rely on Redis, systemd, or long-lived processes. Adapt as needed
for VPS/container hosting (which can just run `spark reach:work` under
supervisord).

For the full blog-automation deploy sequence (flags, public publisher,
Search Console), also see
[`docs/blog-automation/WHM_CPANEL_DEPLOYMENT_RUNBOOK.md`](../blog-automation/WHM_CPANEL_DEPLOYMENT_RUNBOOK.md).

## Commands

- **Worker (loop):**
  ```
  php /home/<cpanel-user>/reach-aicountly/server-php/spark reach:work \
      --queue=default,blog,publishing,community,notifications,distribution --sleep=2 --lease=300 --worker-id=w1
  ```
  Runs until either `writable/stop-reach-worker` exists or the process is
  killed. Emits structured JSON to stdout suitable for log rotation.

- **Worker (single pass, cron-friendly):**
  ```
  php spark reach:work --queue=default,blog,publishing,community,notifications,distribution --once --limit=20 --worker-id=cron
  ```
  Reserves and executes at most N jobs, then exits. Combined with a
  1-minute cron, this drains the job queues without a long-lived process.

  **CRITICAL:** Blog automation enqueues on the `blog` queue (and
  publishing on `publishing`, Community Q&A on `community`, and every
  email/notification job — the 08:00 IST daily analytics report, the
  approval digest, due-date reminders, the cover-generation failure alert — on
  `notifications`). A worker that does not list a queue **never** processes
  its jobs; they sit `pending` with nothing reporting it. Until 2026-10 the
  documented cron line omitted `notifications`, so none of those emails ever
  went out.

  > Bot queue dispatches (Bot → Queue) run on `default`. They used to go to a
  > `marketing_bot` queue that no documented worker drained, so they stayed
  > queued for ever; migration `2026-10-18-230001` moved the stranded ones.

- **Blog dispatch (enqueue eligible work blocks):**
  ```
  php spark reach:blog-dispatch
  ```
  Only runs inside the Asia/Kolkata windows `00:00–08:59` and
  `19:00–23:59` unless `--force` is passed. Without this cron, work
  blocks stay `eligible` and never become jobs.

- **Roadmap optimiser (daily):**
  ```
  php spark reach:blog-optimize-roadmap
  ```
  Preferred window is `00:00–00:59` Asia/Kolkata. Pass `--force` to
  override for manual smoke tests.

- **Scheduler / housekeeping:**
  ```
  php spark reach:schedule
  ```
  - Recovers leases past their `lease_expires_at` back to `pending` — or to
    `dead_letter` when the job has used all its attempts (a job that keeps
    killing its worker is no longer re-run for ever).
  - Prunes finished jobs: completed / skipped / cancelled after
    `--prune-days` (default **7**), dead_letter / failed after
    `--prune-failed-days` (default **14**), at most 5 000 rows per tick.
  - Enqueues the timetable below — each job once per period, attributed to
    the `system` actor.

- **Job queue report (paste this when asking for help):**
  ```
  php spark reach:jobs-report            # last 24 h
  php spark reach:jobs-report --hours=6
  php spark reach:jobs-report --json     # the dashboard's raw figures
  ```
  Prints completed / failed / skipped in the window, what is queued now (and
  the oldest wait), retrying / running / stalled jobs, every failing job type
  with its count and **latest error**, and each queue's backlog — flagging a
  queue whose ready jobs have waited 15+ minutes with nothing finished on it
  in an hour (a queue missing from `--queue=`). Exit code 1 when anything
  failed or is stalled, so it can back a monitor. Same figures as the
  dashboard's *Job queue — last 24 h* card.

### Job outcomes

| Status | Meaning | Retried? |
|--------|---------|----------|
| `completed` | The handler ran. | — |
| `skipped` | Did not run **by design**: the integration is not configured or switched off (no Gemini key for AI Insights, no Search Console connection, no DataForSEO login, no gallery-alert address, …). The reason is in the error column. Not a failure. | No — retry it from the Job Monitor after configuring. |
| `pending` + error | A failed attempt waiting for its backoff (15 s, 30 s, 1 min, … capped at 1 h). | Yes, until `max_attempts` (default 5). |
| `dead_letter` | Failed `max_attempts` times — or at once when a retry cannot help: bad payload, record gone, no handler registered for the job type, a community deployment already dead-lettered. | No. |

Handlers signal a skip by throwing `App\Libraries\JobSkippedException` or by
returning a top-level `status` of `skipped` / `not_configured` / `disabled` /
`not_migrated`; a terminal failure by throwing `NonRetryableJobException`
(see `App\Libraries\JobRunner`).

- **One-shot diagnose (run in cPanel Terminal):**
  ```
  php spark reach:blog-diagnose
  ```
  Reports flags, WRITEPATH/log presence, topic candidates, work blocks,
  jobs, and a verdict with next actions. Exit code `2` means blocking
  issues were found.

## Cron examples

cPanel → **Cron Jobs**. All entries assume the app is installed at
`/home/<user>/reach-aicountly/server-php`. Adjust the PHP binary path
(`which php` / `ls /usr/local/bin/php /usr/bin/php`).

```cron
# Every 2 hours 21:00–09:00 IST — enqueue blog work blocks (API blog route,
# ~6 blogs/day). The window itself is enforced by the command from portfolio
# settings, so an extra firing outside it is a harmless no-op.
# Server clock IST:
0 21,23,1,3,5,7 * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:blog-dispatch >> writable/logs/blog-dispatch.log 2>&1
# Server clock UTC equivalent: 30 15,17,19,21,23,1 * * *

# Daily topic discovery — 00:15 Asia/Kolkata (before optimiser).
# If the server clock is UTC, use: 45 18 * * *  (18:45 UTC = 00:15 IST).
15 0 * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:blog-discover-topics --limit=10 >> writable/logs/blog-discover.log 2>&1

# Daily roadmap optimiser — 00:30 Asia/Kolkata.
# If the server clock is UTC, use: 0 19 * * *  (19:00 UTC = 00:30 IST).
30 0 * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:blog-optimize-roadmap >> writable/logs/blog-optimizer.log 2>&1

# Every minute — drain default + blog + publishing + community + notifications queues.
# (Append ,distribution here if/when that queue exists.)
* * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:work --queue=default,blog,publishing,community,notifications,distribution --once --limit=20 --worker-id=cron >> writable/logs/worker.log 2>&1

# Every minute — automated video pipeline (queue `video`). Its own worker so a
# YouTube upload step (up to ~80 s) never holds up the other queues. Each run
# does at most 3 short steps; see docs/operations/VIDEO_AUTOMATION_SETUP.md.
* * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:work --queue=video --once --limit=3 --lease=300 --worker-id=video >> writable/logs/worker-video.log 2>&1

# Every minute — recover expired leases, prune old jobs, self-heal empty AI routes.
* * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:schedule >> writable/logs/schedule.log 2>&1

# Every 5 minutes — Community Q&A housekeeping (scheduled publish, deployment
# retries, re-vetting routine submissions left pending by unavailable vetting).
*/5 * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark community:schedule >> writable/logs/community-schedule.log 2>&1

# Every 30 minutes — community intake housekeeping: seed curation, intake
# screening, categorisation. It no longer drafts answers — the
# reach-community-qa Claude Routine writes them (docs/automation/routines/).
*/30 * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark community:agents-run >> writable/logs/community-agents.log 2>&1
```

Jobs enqueued by `reach:schedule` (no extra cron lines needed). Hours are
the server clock (UTC) unless marked IST. Every entry carries an idempotency
key scoped to its day or hour, and `reach:schedule` skips a key that already
has a job in any state — so the every-minute tick enqueues each job once per
period, not sixty times.

| When | Job | Queue | Key |
|------|-----|-------|-----|
| every hour | `reach.cover_gallery_autofill` — generates the covers the upcoming blog index is missing (GPT Image, AICOUNTLY logo), capped by `covers.auto.daily_limit` | blog | `cover-gallery-autofill-<Y-m-d-H>` |
| every hour | `reach.blog_cover_sweep` — re-attempts parked articles (re-armed when a new cover lands) | blog | `blog-cover-sweep-<Y-m-d-H>` |
| every hour | `reach.content_due_date_reminder`, `reach.content_overdue_escalation` | notifications | `<type>-<Y-m-d-H>` |
| every hour | `reach.content_schedule_readiness` | default | `content-schedule-readiness-<Y-m-d-H>` |
| 03:00 UTC (08:30 IST) | `reach.content_refresh_detection` | default | per day |
| **04:00 UTC (09:30 IST)** | **`reach.search_console_ingest`** — pulls Search Console (site-wide + blog-mapped) for the incremental window and one slice of any active backfill | default | `search-console-ingest-<date>` |
| 05:00 UTC (10:30 IST) | `reach.seo_snapshot` — tracked-keyword ranks of the freshest ingested GSC day + backlink snapshot | default | per day |
| 06:00 UTC (11:30 IST) | `reach.content_base_sync` | default | per day |
| 07:00 UTC (12:30 IST) | `reach.daily_marketing_pack` (for tomorrow) | default | per target date |
| 08:00 UTC (13:30 IST) | `reach.daily_approval_digest` | notifications | `approval-digest-<date>` |
| 09:00 UTC (14:30 IST) | `reach.gallery_deficit_alert` — informational email **only** when cover generation keeps failing (`covers.alerts.*`); the deficit itself is a line in the daily report | notifications | per day |
| 06:00 IST (00:30 UTC) | `reach.seo_tracker` — DataForSEO SERP checks, capped by `seo.tracker.max_keywords_per_day`; a no-op without credentials | default | `seo-tracker-<IST date>` |
| 08:00 IST (02:30 UTC) | `reach.daily_analytics_report` — email to `reports.daily.recipients`; never re-sent for a date already sent | notifications | `daily-report-<IST date>` |
| 01:00 IST (19:30 UTC) | `reach.bot_plan` mode `daily` — snapshot yesterday's actuals (and the 4 days before, which may still change), review the last 7 days (variance, root causes, lessons), self-tune within bounds (`bot.self_tune.enabled`), regenerate every plan period; `php spark reach:bot-plan` by hand | default | `bot-plan-daily-<IST date>` |
| every other hour (IST) | `reach.bot_plan` mode `refresh` — regenerate the plans only; a new version is stored only when a number changed | default | `bot-plan-refresh-<IST date>-<IST hour>` |

Run any of these (and the cron commands above, and the Claude routines) now from **Bot → Activities** in Reach — it queues the same job / runs the same command; see [`BOT_ACTIONS.md`](BOT_ACTIONS.md).

Run or re-run by hand:

```bash
php spark reach:search-console ingest            # GSC incremental window now
php spark reach:search-console backfill --days=90 # then let the 04:00 UTC (09:30 IST) job walk it (14 days/run)
php spark reach:daily-report                     # build + store today's report (no email)
php spark reach:daily-report --send              # build + email to reports.daily.recipients
php spark reach:daily-report --date=2026-10-17 --send --to=you@aicountly.com
php spark reach:email-providers                  # which email providers send (names only, from Console)
php spark reach:email-providers --send=you@aicountly.com [--resend]   # test mail, normal / fallback-first order
```

Report and campaign mail go through Console's email providers for reach (primary, then fallback);
an explicit `--send` / UI "Send now" of a date already attempted is a resend (fallback first). The
report's pipeline-health section names them, e.g. "email via itwalk_api, fallback aicountly_smtp".

Search Console setup: [`SEARCH_CONSOLE_SETUP.md`](SEARCH_CONSOLE_SETUP.md).
Daily jobs handled by `reach:schedule` (no extra cron lines needed):
05:00 UTC (10:30 IST) SEO rank/backlink snapshots · 06:00 UTC (11:30 IST) content-base sync ·
09:00 UTC (14:30 IST) cover-generation failure alert (sent only on repeated failures). Times shown in the UI, emails and CLI are IST —
[`TIMEZONES.md`](TIMEZONES.md).
Every 5 minutes `reach:schedule` also enqueues `reach.routine_content_sweep`
(default queue), which carries Claude-routine blog/KB submissions forward —
re-vetting with Gemini after an outage, hourly cover retries, publishing and
verification. Run it by hand with `php spark reach:routine-sweep` (or
`--id=N` for one submission). It does not depend on the `reach:blog-dispatch`
window: routine articles publish during the day.
Every hour `reach:schedule` also enqueues the video pipeline sweep (queue `video`:
re-vets scripts left pending, retries blocked videos, re-queues lost steps).

After every deploy (with AI keys already in `.env`):

```bash
php spark reach:ai-seed-catalog
```

**If `writable/logs/` stays empty**, the crontab `cd` path is wrong, the
PHP binary path is wrong, or these cron lines were never installed. Cron
log files are created only by the `>> writable/logs/...` redirects — the
PHP commands do not create those filenames by themselves.

For a "second worker slot" without needing two long-lived processes,
duplicate the worker entry with a different `--worker-id=cron2` and offset
the minute by using two entries; cron's minimum granularity is one minute
so add a `sleep` to stagger:

```cron
* * * * * (sleep 20; cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:work --queue=default,blog,publishing,community,notifications,distribution --once --limit=5 --worker-id=cron2 >> writable/logs/worker.log 2>&1)
```

## Required `.env` flags for blogs to be saved

Safe defaults ship **off**. For a pilot that creates briefs/drafts (but
does not auto-publish):

```env
BLOG_ROADMAP_OPTIMIZER_ENABLED=true
BLOG_AUTOMATION_ENABLED=true
BLOG_AI_GENERATION_ENABLED=true
BLOG_AUTO_PUBLISH_ENABLED=false
```

Also ensure eligible rows exist in `reach_topic_candidates`
(`status` = `candidate` or `scored`, not locked, not in cooldown).

## Graceful stop pattern

`ReachWork` checks `writable/stop-reach-worker` at the start of every
iteration. To drain a long-running worker before deploy:

```bash
touch server-php/writable/stop-reach-worker
```

The worker finishes its current job (if any) and exits cleanly. Delete
the file to allow the next worker instance to start.

## Log rotation

The worker writes one JSON line per event. `writable/logs/worker.log`
can be rotated with cPanel's standard log-rotate config or an in-app
weekly cron:

```cron
0 0 * * 0 mv /home/<user>/reach-aicountly/server-php/writable/logs/worker.log /home/<user>/reach-aicountly/server-php/writable/logs/worker.log.$(date +\%Y\%m\%d)
```

## Failure recovery playbook

1. **No files under `writable/logs/`:** crontab missing or wrong `cd` /
   PHP path. Run `php spark reach:blog-diagnose`, then install the four
   cron lines above.
2. **All jobs stuck in `processing`:** likely a worker crash mid-job.
   Wait for `reach:schedule` to recover leases (runs every minute), or
   run `php spark reach:schedule` manually.
3. **Jobs stuck `pending` forever (blog, publishing, emails):** the worker
   does not list that queue. Use
   `--queue=default,blog,publishing,community,notifications,distribution`. The Job Monitor
   shows each job's queue; the daily report's *Pipeline health* section
   counts jobs pending for over an hour.
4. **Work blocks stay `eligible`:** `reach:blog-dispatch` cron is missing
   or outside the IST window (use `--force` for a manual smoke).
5. **Handler always fails:** inspect `error_message` and `attempts` in
   the Job Monitor. If it's the handler code, fix + deploy, then retry
   the dead-letter jobs from the UI.
6. **Rate-limited outbound (Engage/Console):** back off by pausing the
   worker (`touch stop-reach-worker`), increasing `sleep`, or reducing
   `--limit`.
7. **Bot dispatch surge:** the `bot.dispatch` route is IP+user throttled
   (30/min/user); if you need higher throughput, add a dedicated `bots`
   queue and start a second worker slot for it.
8. **Community answers stuck before publication** (`draft_requested`,
   `validation_failed`, `draft_generated`, review queues): these are left over
   from the old in-Reach drafting, which `community:agents-run` no longer does.
   `php spark community:legacy-drafts` lists them; `--clear --confirm`
   archives them so their questions appear in the routine's
   `community-plan` open questions again. Routine submissions themselves are
   tracked in Community → Automated feed.
9. **Daily report `failed`:** open Reach → Reports; the row's error says why
   (usually `EMAIL_API_KEY is not configured`). Fix the env, then "Send now"
   (super admin) or `php spark reach:daily-report --send`.

## Monitoring

- `GET v1/admin/worker-status/ping` (permission: `job.view`, throttled)
  is a lightweight ok/error hook for external uptime checks. It also
  records the last ping timestamp in `reach_worker_health_snapshots`.
- The Job Monitor page at `/admin/jobs` filters by status, worker id,
  and job type, and exposes retry / cancel actions to permitted users.
- Every job lifecycle transition emits an audit event
  (`job.enqueued|reserved|completed|retried|failed|cancelled`) that
  fans out to Console via `reach.*`.
- Blog Command Centre → Overview reflects content/deploy counts; empty
  Production/Publishing means no saved blogs / published deployments yet,
  not that AI keys are missing (AI Ops OK only means providers are configured).

## Do NOT do

- Do **not** run more than one long-lived `reach:work` process on cPanel
  without confirming the host allows it. Most shared hosts kill
  long-running PHP processes; the `--once` cron pattern is safer.
- Do **not** call `JobService::enqueue` from inside an untested handler —
  the handler is already inside a job; nested enqueues are fine but
  should carry `request_id` for correlation.
- Do **not** touch `writable/htmlpurifier` from cron; it's populated on
  first use and safe to leave alone.
- Do **not** install only the Phase 0 worker/schedule crons and expect
  blogs to generate — you also need `reach:blog-dispatch` and
  `reach:blog-optimize-roadmap`.

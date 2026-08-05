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
      --queue=default,blog,publishing --sleep=2 --lease=300 --worker-id=w1
  ```
  Runs until either `writable/stop-reach-worker` exists or the process is
  killed. Emits structured JSON to stdout suitable for log rotation.

- **Worker (single pass, cron-friendly):**
  ```
  php spark reach:work --queue=default,blog,publishing --once --limit=20 --worker-id=cron
  ```
  Reserves and executes at most N jobs, then exits. Combined with a
  1-minute cron, this drains the job queues without a long-lived process.

  **CRITICAL:** Blog automation enqueues on the `blog` queue (and
  publishing on `publishing`). A worker started with only
  `--queue=default` will **never** process those jobs.

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
  - Recovers leases past their `lease_expires_at` back to `pending`.
  - Prunes completed / dead-lettered / cancelled jobs older than N days
    (default 14, adjust with `--prune-days=`).
  - Enqueues Phase 2 daily housekeeping jobs.

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

# Every minute — drain default + blog + publishing + community queues.
* * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:work --queue=default,blog,publishing,community --once --limit=20 --worker-id=cron >> writable/logs/worker.log 2>&1

# Every minute — recover expired leases, prune old jobs, self-heal empty AI routes.
* * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:schedule >> writable/logs/schedule.log 2>&1

# Every 5 minutes — Community Q&A housekeeping (scheduled publish + retries).
*/5 * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark community:schedule >> writable/logs/community-schedule.log 2>&1

# Every 30 minutes — community agent work selection (disclosed identities;
# window 09:00–19:00 IST and daily caps enforced inside the service).
*/30 * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark community:agents-run >> writable/logs/community-agents.log 2>&1
```

Daily jobs handled by `reach:schedule` (no extra cron lines needed):
05:00 SEO rank/backlink snapshots · 06:00 content-base sync ·
09:00 cover-gallery deficit email.

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
* * * * * (sleep 20; cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:work --queue=default,blog,publishing --once --limit=5 --worker-id=cron2 >> writable/logs/worker.log 2>&1)
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
3. **Blog jobs stuck `pending` forever:** worker is probably still on
   `--queue=default` only. Switch to `--queue=default,blog,publishing`.
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

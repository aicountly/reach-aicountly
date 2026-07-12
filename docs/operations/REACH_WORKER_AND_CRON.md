# REACH Worker and Cron Guide — Phase 0

This document targets cPanel-style shared-hosting environments where we
cannot rely on Redis, systemd, or long-lived processes. Adapt as needed
for VPS/container hosting (which can just run `spark reach:work` under
supervisord).

## Commands

- **Worker (loop):**
  ```
  php /home/<cpanel-user>/reach-aicountly/server-php/spark reach:work \
      --queue=default --sleep=2 --lease=300 --worker-id=w1
  ```
  Runs until either `writable/stop-reach-worker` exists or the process is
  killed. Emits structured JSON to stdout suitable for log rotation.

- **Worker (single pass, cron-friendly):**
  ```
  php spark reach:work --queue=default --once --limit=5 --worker-id=cron
  ```
  Reserves and executes at most 5 jobs, then exits. Combined with a
  1-minute cron, this gives 5 concurrent job attempts per minute per
  worker slot.

- **Scheduler / housekeeping:**
  ```
  php spark reach:schedule
  ```
  - Recovers leases past their `lease_expires_at` back to `pending`.
  - Prunes completed / dead-lettered / cancelled jobs older than N days
    (default 30, adjust with `--prune-days=`).
  - Advances scheduled jobs whose `available_at` has arrived (no-op today
    because `JobService::reserve` already respects the timestamp).

## Cron examples

cPanel → **Cron Jobs**. All entries assume the app is installed at
`/home/<user>/reach-aicountly/server-php`. Adjust the PHP binary path.

```cron
# Every minute — one queue pass, up to 5 jobs per invocation.
* * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:work --queue=default --once --limit=5 --worker-id=cron >> writable/logs/worker.log 2>&1

# Every minute — recover expired leases, prune old jobs (idempotent, cheap).
* * * * * cd /home/<user>/reach-aicountly/server-php && /usr/local/bin/php spark reach:schedule >> writable/logs/schedule.log 2>&1
```

For a "second worker slot" without needing two long-lived processes,
duplicate the first entry with a different `--worker-id=cron2` and offset
the minute by using two entries; cron's minimum granularity is one minute
so add a `sleep` to stagger:

```cron
* * * * * (sleep 20; cd ...; php spark reach:work --once --limit=5 --worker-id=cron2)
```

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
0 0 * * 0 mv server-php/writable/logs/worker.log server-php/writable/logs/worker.log.$(date +\%Y\%m\%d)
```

## Failure recovery playbook

1. **All jobs stuck in `processing`:** likely a worker crash mid-job.
   Wait for `reach:schedule` to recover leases (runs every minute), or
   run `php spark reach:schedule` manually.
2. **Handler always fails:** inspect `error_message` and `attempts` in
   the Job Monitor. If it's the handler code, fix + deploy, then retry
   the dead-letter jobs from the UI.
3. **Rate-limited outbound (Engage/Console):** back off by pausing the
   worker (`touch stop-reach-worker`), increasing `sleep`, or reducing
   `--limit`.
4. **Bot dispatch surge:** the `bot.dispatch` route is IP+user throttled
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

## Do NOT do

- Do **not** run more than one long-lived `reach:work` process on cPanel
  without confirming the host allows it. Most shared hosts kill
  long-running PHP processes; the `--once` cron pattern is safer.
- Do **not** call `JobService::enqueue` from inside an untested handler —
  the handler is already inside a job; nested enqueues are fine but
  should carry `request_id` for correlation.
- Do **not** touch `writable/htmlpurifier` from cron; it's populated on
  first use and safe to leave alone.

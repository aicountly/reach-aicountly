<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark reach:schedule` — housekeeping for the reach_jobs queue.
 *
 *   1. Recover leases that have expired past `lease_expires_at`.
 *   2. Prune completed / dead-letter / cancelled jobs older than N days.
 *
 * Intended to run every minute via cron:
 *   * * * * * cd /path/to/server-php && php spark reach:schedule
 */
class ReachSchedule extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:schedule';
    protected $description = 'Housekeeping for the reach_jobs queue: recover expired leases and prune old jobs.';
    protected $usage       = 'reach:schedule [--prune-days=14]';

    public function run(array $params): int
    {
        $days = max(1, (int) ($params['prune-days'] ?? CLI::getOption('prune-days') ?? 14));

        $svc = Services::jobService();
        $recovered = $svc->recoverExpiredLeases();
        $pruned    = $svc->pruneOlderThanDays($days);

        // Dispatch scheduled Phase 2 jobs once per day (idempotent)
        $this->dispatchDailyJobs($svc);

        $out = json_encode([
            'event'     => 'schedule.tick',
            'ts'        => gmdate('c'),
            'recovered' => $recovered,
            'pruned'    => $pruned,
            'prune_days'=> $days,
        ], JSON_UNESCAPED_SLASHES);
        CLI::write($out);
        return 0;
    }

    /**
     * Enqueue Phase 2 scheduled jobs. Each job class is idempotent so
     * duplicate enqueues within the same day are safe but wasteful; a
     * lightweight dedup uses the job `queue_key` column when available.
     */
    private function dispatchDailyJobs(mixed $svc): void
    {
        $today = date('Y-m-d');
        $hour  = (int) date('H');

        // 08:00 — daily approval digest
        if ($hour === 8) {
            $svc->enqueue('reach.daily_approval_digest', ['date' => $today], ['queue' => 'notifications']);
        }

        // 07:00 — generate tomorrow's marketing pack
        if ($hour === 7) {
            $svc->enqueue('reach.daily_marketing_pack', [
                'date' => date('Y-m-d', strtotime('+1 day')),
            ]);
        }

        // Every hour: due-date reminders, overdue escalation, schedule readiness
        $svc->enqueue('reach.content_due_date_reminder',  [], ['queue' => 'notifications']);
        $svc->enqueue('reach.content_overdue_escalation', [], ['queue' => 'notifications']);
        $svc->enqueue('reach.content_schedule_readiness', []);

        // 03:00 — refresh detection
        if ($hour === 3) {
            $svc->enqueue('reach.content_refresh_detection', []);
        }
    }
}

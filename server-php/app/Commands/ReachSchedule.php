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
}

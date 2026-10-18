<?php

namespace App\Commands;

use App\Libraries\CronHeartbeat;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * `php spark reach:console-audit-deliver` — retry the central-audit events Console has not taken
 * yet (reach_console_sync_logs, status pending, due) to POST {CONSOLE_API_URL}/portal/audit in
 * batches of 100. Events are normally delivered the moment they happen; this catches the rest
 * (back-off 2, 4, 8 … minutes, at most 6 hours; dead after 12 attempts).
 *
 * Cron (every 5 minutes): scheduled by the production deploy from bin/ops/jobs.txt (README "Production deployment
 * (cPanel)" step 9) — not pasted into cPanel by hand.
 */
class ReachConsoleAuditDeliver extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:console-audit-deliver';
    protected $description = 'Retry central-audit events Console has not taken yet (POST {CONSOLE_API_URL}/portal/audit).';
    protected $usage       = 'reach:console-audit-deliver';

    public function run(array $params): int
    {
        $client = Services::consoleAudit();
        if (! $client->isConfigured()) {
            CLI::error('CONSOLE_API_URL / CONSOLE_API_TOKEN not set — nothing can be sent.');

            return EXIT_ERROR;
        }

        $r = $client->deliverDue();
        CLI::write(sprintf(
            'Console audit: %d delivered, %d rejected, %d to retry, %d dead (%d batch%s).',
            $r['delivered'], $r['rejected'], $r['retry'], $r['dead'], $r['batches'], $r['batches'] === 1 ? '' : 'es'
        ));

        // Console's Cron Job Monitor (reach.console_audit_deliver; CONSOLE_CRON_MONITOR_KEY in .env, console-react-app
        // docs/CRON_MONITORS.md): a delivery run reports ok with its counts. Inert without a key; a failed
        // report never fails this command.
        CronHeartbeat::send('reach.console_audit_deliver', 'ok', '', array_map('intval', array_intersect_key($r, array_flip(['delivered', 'rejected', 'retry', 'dead', 'batches']))));

        return EXIT_SUCCESS;
    }
}

<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Throwable;

/**
 * `php spark reach:work` — long-running worker that reserves and executes jobs.
 *
 * Options:
 *   --queue=<name>       (default: "default")
 *   --once               process at most one job then exit (useful for cron)
 *   --limit=<n>          exit after N jobs (default 0 = unlimited)
 *   --worker-id=<slug>   distinct id emitted in logs and stored on the job row
 *   --sleep=<seconds>    idle sleep between reservation attempts (default 2)
 *   --lease=<seconds>    lease duration (default 300)
 *
 * Stop file: touch `writable/stop-reach-worker` to request a graceful shutdown
 * at the end of the current iteration (cPanel-friendly signal replacement).
 */
class ReachWork extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:work';
    protected $description = 'Reserve and run Reach jobs from the reach_jobs queue.';
    protected $usage       = 'reach:work [--queue=default] [--once] [--limit=10] [--worker-id=w1] [--sleep=2] [--lease=300]';

    public function run(array $params): int
    {
        $queue    = (string) ($params['queue'] ?? CLI::getOption('queue') ?? 'default');
        $once     = (bool) (CLI::getOption('once') ?? false);
        $limit    = (int) ($params['limit'] ?? CLI::getOption('limit') ?? 0);
        $workerId = (string) ($params['worker-id'] ?? CLI::getOption('worker-id') ?? gethostname() . '.' . getmypid());
        $sleep    = max(0, (int) ($params['sleep'] ?? CLI::getOption('sleep') ?? 2));
        $lease    = max(30, (int) ($params['lease'] ?? CLI::getOption('lease') ?? 300));
        $stopFile = WRITEPATH . 'stop-reach-worker';

        $svc      = Services::jobService();
        $registry = Services::jobHandlers();
        $processed = 0;

        $this->log('worker.start', [
            'worker_id' => $workerId, 'queue' => $queue, 'once' => $once, 'limit' => $limit, 'sleep' => $sleep,
        ]);

        while (true) {
            if (is_file($stopFile)) {
                $this->log('worker.stop_requested', ['worker_id' => $workerId]);
                break;
            }

            $row = $svc->reserve($queue, $workerId, $lease);
            if (! $row) {
                if ($once) {
                    break;
                }
                if ($sleep > 0) {
                    sleep($sleep);
                }
                continue;
            }

            $jobId = (int) $row['id'];
            // Restore the originating request-id onto the fake request so
            // downstream outbound HTTP calls thread it back through
            // ConsoleAuditClient/EngageClient/AicountlySitePublisher.
            $jobRequestId = $row['request_id'] ?? null;
            if (is_string($jobRequestId) && $jobRequestId !== '') {
                try {
                    service('request')->reachRequestId = $jobRequestId;
                } catch (Throwable $e) {
                    // request service unavailable in some CLI contexts — ignore.
                }
            }

            $this->log('job.reserved', [
                'job_id'     => $jobId,
                'job_uuid'   => $row['job_uuid'] ?? null,
                'job_type'   => $row['job_type'],
                'attempt'    => (int) $row['attempts'],
                'worker_id'  => $workerId,
                'request_id' => $jobRequestId,
            ]);

            try {
                $result = $registry->execute($row, $workerId);
                $svc->markCompleted($jobId, $result);
                $this->log('job.completed', ['job_id' => $jobId, 'job_type' => $row['job_type'], 'request_id' => $jobRequestId]);
            } catch (Throwable $e) {
                $svc->markFailed($jobId, $e->getMessage());
                $this->log('job.failed', [
                    'job_id'     => $jobId,
                    'job_type'   => $row['job_type'],
                    'error'      => $e->getMessage(),
                    'request_id' => $jobRequestId,
                ]);
            }

            $processed++;
            if ($once || ($limit > 0 && $processed >= $limit)) {
                break;
            }
        }

        $this->log('worker.exit', ['worker_id' => $workerId, 'processed' => $processed]);
        return $processed >= 0 ? 0 : 1;
    }

    private function log(string $event, array $ctx): void
    {
        $ctx['event'] = $event;
        $ctx['ts']    = gmdate('c');
        CLI::write(json_encode($ctx, JSON_UNESCAPED_SLASHES));
    }
}

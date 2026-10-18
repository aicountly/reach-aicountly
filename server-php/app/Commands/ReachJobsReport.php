<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Jobs\JobQueueInsights;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:jobs-report [--hours=24] [--json]` — the queue's health in
 * a form the operator can paste: what ran, what failed (with the latest error
 * per job type), what is stuck, and queues nobody seems to drain.
 *
 * Read-only. Same figures as the dashboard's Job queue card
 * (JobQueueInsights), so the two never disagree.
 */
class ReachJobsReport extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:jobs-report';
    protected $description = 'Print failing job types (with last errors), stuck jobs and queue backlog for the last N hours.';
    protected $usage       = 'reach:jobs-report [--hours=24] [--json]';

    public function run(array $params): int
    {
        $hours = max(1, min(720, (int) ($this->sparkOption('hours', $params, '24') ?? '24')));
        $s     = (new JobQueueInsights())->summary($hours);

        if ($this->sparkFlag('json', $params)) {
            CLI::write((string) json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        foreach (self::render($s) as $line) {
            CLI::write($line);
        }

        return ($s['failed'] ?? 0) > 0 || ($s['stalled'] ?? 0) > 0 ? 1 : 0;
    }

    /**
     * Plain-text report lines. Pure, so it is unit-tested.
     *
     * @param array<string, mixed> $s JobQueueInsights::summary()
     * @return list<string>
     */
    public static function render(array $s): array
    {
        if (! ($s['available'] ?? false)) {
            return ['reach_jobs table not found — run php spark migrate.'];
        }
        $h   = (int) $s['window_hours'];
        $out = [
            "Reach job queue — last {$h} h (generated " . gmdate('Y-m-d H:i') . ' UTC)',
            sprintf(
                'completed %d · failed %d · skipped %d | now: queued %d (oldest %s) · scheduled later %d · retrying %d · running %d · stalled %d',
                $s['completed'], $s['failed'], $s['skipped'], $s['queued'], self::age($s['oldest_queued_secs']),
                $s['scheduled'], $s['retrying'], $s['running'], $s['stalled'],
            ),
            '',
        ];

        $types = $s['failing_types'] ?? [];
        if ($types === []) {
            $out[] = "No job type failed or is retrying in the last {$h} h.";
        } else {
            $out[] = "Failing job types (last {$h} h):";
            foreach ($types as $t) {
                $out[] = sprintf('  %-44s failed %4d  retrying %3d  last %s', $t['job_type'], $t['failed'], $t['retrying'], $t['last_at'] ?? '—');
                $err = trim(preg_replace('/\s+/', ' ', (string) ($t['last_error'] ?? '')) ?? '');
                $out[] = '      last error: ' . ($err !== '' ? mb_substr($err, 0, 300) : '(none recorded)');
            }
        }

        $queues = $s['queues'] ?? [];
        if ($queues !== []) {
            $out[] = '';
            $out[] = 'Queues:';
            foreach ($queues as $q) {
                $out[] = sprintf(
                    '  %-14s queued %4d (oldest %s)  running %2d  completed %5d  failed %4d%s',
                    $q['queue'], $q['queued'], self::age($q['oldest_queued_secs']), $q['running'], $q['completed'], $q['failed'],
                    $q['undrained'] ? '  << no worker seems to drain this queue: add it to reach:work --queue=' : '',
                );
            }
        }
        if (($s['stalled'] ?? 0) > 0) {
            $out[] = '';
            $out[] = "{$s['stalled']} job(s) hold an expired lease (worker died mid-job); reach:schedule re-queues them, or dead-letters them once attempts are used up.";
        }

        return $out;
    }

    private static function age(?int $secs): string
    {
        if ($secs === null) {
            return '—';
        }

        return match (true) {
            $secs < 120   => $secs . 's',
            $secs < 7200  => intdiv($secs, 60) . 'm',
            $secs < 172800 => intdiv($secs, 3600) . 'h',
            default       => intdiv($secs, 86400) . 'd',
        };
    }
}

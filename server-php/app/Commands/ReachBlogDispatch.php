<?php

namespace App\Commands;

use App\Libraries\Blog\WorkBlockService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;

/**
 * `php spark reach:blog-dispatch` — enqueue eligible blog work blocks inside the automation window.
 */
class ReachBlogDispatch extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-dispatch';
    protected $description = 'Dispatch eligible blog work blocks within the Asia/Kolkata automation window.';
    protected $usage       = 'reach:blog-dispatch [--force] [--limit=25]';

    public function run(array $params): int
    {
        $force = (bool) (CLI::getOption('force') ?? ($params['force'] ?? false));
        $limit = max(1, (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 25)));

        if (! $force && ! $this->isWithinAutomationWindow()) {
            $out = json_encode([
                'event'   => 'blog_dispatch.skipped',
                'reason'  => 'outside_automation_window',
                'tz'      => 'Asia/Kolkata',
                'now'     => $this->nowKolkata()->format('H:i'),
            ], JSON_UNESCAPED_SLASHES);
            CLI::write($out);
            return 0;
        }

        $lockFile = WRITEPATH . 'reach-blog-dispatch.lock';
        $fp       = fopen($lockFile, 'c+');
        if ($fp === false || ! flock($fp, LOCK_EX | LOCK_NB)) {
            CLI::error('Another blog dispatch is already running.');
            if ($fp !== false) {
                fclose($fp);
            }
            return 1;
        }

        try {
            $result = (new WorkBlockService())->enqueueEligibleBatch($limit);
            $out    = json_encode([
                'event'          => 'blog_dispatch.completed',
                'ts'             => gmdate('c'),
                'forced'         => $force,
                'limit'          => $limit,
                'enqueued'       => $result['enqueued'],
                'skipped'        => $result['skipped'],
                'work_block_ids' => $result['work_block_ids'],
            ], JSON_UNESCAPED_SLASHES);
            CLI::write($out);
            return 0;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function isWithinAutomationWindow(): bool
    {
        $now   = $this->nowKolkata();
        $mins  = ((int) $now->format('H')) * 60 + (int) $now->format('i');

        $windows = [
            [0, 8 * 60 + 59],
            [19 * 60, 23 * 60 + 59],
        ];

        foreach ($windows as [$start, $end]) {
            if ($mins >= $start && $mins <= $end) {
                return true;
            }
        }

        return false;
    }

    private function nowKolkata(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    }
}

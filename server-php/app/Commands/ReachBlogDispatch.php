<?php

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
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
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-dispatch';
    protected $description = 'Dispatch eligible blog work blocks within the Asia/Kolkata automation window.';
    protected $usage       = 'reach:blog-dispatch [--force] [--limit=25]';

    public function run(array $params): int
    {
        $force = $this->sparkFlag('force', $params);
        $limit = max(1, (int) ($this->sparkOption('limit', $params, '25') ?? 25));

        if (! $force && ! $this->isWithinAutomationWindow()) {
            $out = json_encode([
                'event'   => 'blog_dispatch.skipped',
                'reason'  => 'outside_automation_window',
                'tz'      => $this->windowTimezone(),
                'now'     => $this->nowInWindowTz()->format('H:i'),
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
        $now  = $this->nowInWindowTz();
        $mins = ((int) $now->format('H')) * 60 + (int) $now->format('i');

        foreach ($this->configuredWindows() as [$start, $end]) {
            if ($mins >= $start && $mins <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Windows come from reach_blog_portfolio_config.settings_json.automation_window
     * (editable in Blog Command Centre → Settings → Automation Window); the
     * hardcoded 21:00–09:00 IST pair is only the fallback for a missing or
     * malformed config.
     *
     * @return list<array{0:int,1:int}> minute-of-day ranges
     */
    private function configuredWindows(): array
    {
        $fallback = [
            [0, 8 * 60 + 59],
            [21 * 60, 23 * 60 + 59],
        ];

        $windows = [];
        foreach ($this->windowConfig()['windows'] ?? [] as $window) {
            $start = $this->parseMinutes((string) ($window['start'] ?? ''));
            $end   = $this->parseMinutes((string) ($window['end'] ?? ''));
            if ($start !== null && $end !== null && $end >= $start) {
                $windows[] = [$start, $end];
            }
        }

        return $windows !== [] ? $windows : $fallback;
    }

    private function parseMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }
        $hours = (int) $m[1];
        $mins  = (int) $m[2];
        if ($hours > 23 || $mins > 59) {
            return null;
        }

        return $hours * 60 + $mins;
    }

    private function windowTimezone(): string
    {
        $tz = (string) ($this->windowConfig()['timezone'] ?? '');

        try {
            new DateTimeZone($tz);
        } catch (\Throwable) {
            $tz = '';
        }

        return $tz !== '' ? $tz : 'Asia/Kolkata';
    }

    /**
     * @return array<string,mixed>
     */
    private function windowConfig(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $config = [];

        try {
            $row = db_connect()->table('reach_blog_portfolio_config')
                ->orderBy('id', 'ASC')->limit(1)->get()->getRowArray();
            $settings = is_string($row['settings_json'] ?? null)
                ? (json_decode($row['settings_json'], true) ?: [])
                : (array) ($row['settings_json'] ?? []);
            $config = is_array($settings['automation_window'] ?? null)
                ? $settings['automation_window']
                : [];
        } catch (\Throwable) {
            $config = [];
        }

        return $config;
    }

    private function nowInWindowTz(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->windowTimezone()));
    }
}

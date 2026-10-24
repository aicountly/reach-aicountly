<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Media\CoverRefreshService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * `php spark reach:covers-refresh [--dry-run] [--limit=N]`
 *
 * Finds live blog posts whose cover is missing (aicountly.com then shows a
 * random stock photo), not a Reach cover, an operator upload / legacy image,
 * or lacks the Aicountly logo, generates a fresh branded cover from each
 * post's own title, summary and headings, and re-publishes the post through
 * the normal publisher with the same content version (cover change only).
 *
 * Bounded by covers.auto.daily_limit — run it again tomorrow (or put it in
 * cron) for the rest. --dry-run lists what would change and spends nothing.
 */
class ReachCoversRefresh extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:covers-refresh';
    protected $description = 'Regenerate branded, topic-drawn covers for live posts that lack one, and republish them (cover only).';
    protected $usage       = 'reach:covers-refresh [--dry-run] [--limit=10]';
    protected $options     = [
        '--dry-run' => 'Report what would change; generate and publish nothing.',
        '--limit'   => 'Posts to handle this run (default 10; the daily cap still applies).',
    ];

    public function run(array $params): int
    {
        $dryRun = $this->sparkFlag('dry-run', $params);
        $limit  = max(1, (int) ($this->sparkOption('limit', $params, '10') ?? '10'));

        try {
            $report = (new CoverRefreshService())->run($dryRun, $limit);
        } catch (Throwable $e) {
            CLI::error('reach:covers-refresh failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf(
            '%s: %d live post(s) need a new cover; %d left in today\'s cap of %d.',
            $dryRun ? 'Dry run' : 'Refresh',
            $report['found'],
            $report['remaining_today'],
            $report['daily_limit'],
        ), $dryRun ? 'yellow' : 'green');

        if (isset($report['error'])) {
            CLI::error($report['error']);
            CLI::write(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return EXIT_ERROR;
        }

        if ($report['items'] !== []) {
            CLI::table(array_map(static fn (array $r) => [
                $r['id'], mb_substr($r['title'], 0, 50), $r['reason'], $r['action'], (string) ($r['deployment_id'] ?? ''), mb_substr((string) ($r['error'] ?? ''), 0, 60),
            ], $report['items']), ['#', 'title', 'why', 'action', 'deployment', 'error']);
        }
        if ($report['deferred'] > 0) {
            CLI::write("{$report['deferred']} post(s) deferred to a later run (limit / daily cap).", 'yellow');
        }
        if (! $dryRun && $report['items'] !== []) {
            CLI::write('Run the publishing worker to send the updates: php spark reach:work --queue=publishing --once --limit=10');
        }
        CLI::write(json_encode(['event' => 'covers_refresh.completed'] + $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return EXIT_SUCCESS;
    }
}

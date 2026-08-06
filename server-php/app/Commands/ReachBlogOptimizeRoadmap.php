<?php

namespace App\Commands;

use App\Libraries\Blog\Roadmap\RoadmapOptimizerService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;

/**
 * `php spark reach:blog-optimize-roadmap` — daily deferred roadmap scoring and decisions.
 */
class ReachBlogOptimizeRoadmap extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-optimize-roadmap';
    protected $description = 'Score topic candidates and produce roadmap decisions (preferred 00:30 Asia/Kolkata).';
    protected $usage       = 'reach:blog-optimize-roadmap [--force] [--date=YYYY-MM-DD] [--dry-run] [--limit=]';

    public function run(array $params): int
    {
        $force  = (bool) (CLI::getOption('force') ?? ($params['force'] ?? false));
        $dryRun = (bool) (CLI::getOption('dry-run') ?? ($params['dry-run'] ?? false));
        $limit  = CLI::getOption('limit') ?? ($params['limit'] ?? null);
        $limit  = $limit !== null && $limit !== '' ? max(0, (int) $limit) : 0;

        $dateOpt = CLI::getOption('date') ?? ($params['date'] ?? null);
        $forDate = is_string($dateOpt) && $dateOpt !== ''
            ? $dateOpt
            : $this->nowKolkata()->format('Y-m-d');

        if (! $force && ! $this->isWithinPreferredHour()) {
            $out = json_encode([
                'event'  => 'blog_optimize_roadmap.skipped',
                'reason' => 'outside_preferred_hour',
                'tz'     => 'Asia/Kolkata',
                'now'    => $this->nowKolkata()->format('H:i'),
                'hint'   => 'Preferred window is 00:00–00:59 IST; pass --force to override.',
            ], JSON_UNESCAPED_SLASHES);
            CLI::write($out);
            return 0;
        }

        $lockFile = WRITEPATH . 'reach-blog-optimize-roadmap.lock';
        $fp       = fopen($lockFile, 'c+');
        if ($fp === false || ! flock($fp, LOCK_EX | LOCK_NB)) {
            CLI::error('Another roadmap optimiser run is already in progress.');
            if ($fp !== false) {
                fclose($fp);
            }
            return 1;
        }

        try {
            $summary = (new RoadmapOptimizerService())->run($forDate, [
                'dry_run' => $dryRun,
                'limit'   => $limit,
            ]);

            $this->printSummaryTable($summary);
            CLI::newLine();
            CLI::write(json_encode([
                'event'   => 'blog_optimize_roadmap.completed',
                'ts'      => gmdate('c'),
                'forced'  => $force,
                'dry_run' => $dryRun,
                'date'    => $forDate,
                'summary' => $summary,
            ], JSON_UNESCAPED_SLASHES));

            return 0;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function printSummaryTable(array $summary): void
    {
        CLI::write('Roadmap optimiser summary');
        CLI::write(str_repeat('-', 72));
        CLI::write(sprintf(
            'Run #%s | date=%s | status=%s | dry_run=%s',
            $summary['run_id'] ?? '-',
            $summary['run_for_date'] ?? '-',
            $summary['status'] ?? '-',
            ! empty($summary['dry_run']) ? 'yes' : 'no',
        ));
        CLI::write(sprintf(
            'Considered=%d Scored=%d Decisions=%d WorkBlocks=%d',
            (int) ($summary['candidates_considered'] ?? 0),
            (int) ($summary['candidates_scored'] ?? 0),
            (int) ($summary['decisions_created'] ?? 0),
            (int) ($summary['work_blocks_created'] ?? 0),
        ));

        if (! empty($summary['skipped_reason'])) {
            CLI::write('Skipped: ' . $summary['skipped_reason']);
        }

        $caps = $summary['caps'] ?? [];
        if ($caps !== []) {
            CLI::write(sprintf(
                'Caps: daily %d/%d | weekly %d/%d (since %s)%s',
                (int) ($caps['daily_selected'] ?? 0),
                (int) ($caps['daily_cap'] ?? 0),
                (int) ($caps['weekly_used'] ?? 0),
                (int) ($caps['weekly_cap'] ?? 0),
                (string) ($caps['weekly_window_from'] ?? '-'),
                ! empty($caps['weekly_cap_reached']) ? '  << WEEKLY CAP REACHED — no new items until it rolls off' : '',
            ));
            if (! empty($caps['weekly_cap_below_daily'])) {
                CLI::error(
                    'BLOG_ROADMAP_MAX_WEEKLY_PUBLICATIONS is lower than BLOG_ROADMAP_MAX_DAILY_CANDIDATES: '
                    . 'one day of output consumes the whole week and production stops until it rolls off.'
                );
            }
        }

        $ranked = $summary['ranked'] ?? [];
        if ($ranked === []) {
            CLI::write('No candidates processed.');
            return;
        }

        CLI::newLine();
        CLI::write(sprintf('%-6s %-8s %-12s %-40s', 'Rank', 'Score', 'Decision', 'Title'));
        CLI::write(str_repeat('-', 72));
        $rank = 1;
        foreach ($ranked as $row) {
            CLI::write(sprintf(
                '%-6d %-8s %-12s %-40s',
                $rank++,
                (string) ($row['total_score'] ?? '0'),
                (string) ($row['decision'] ?? '-'),
                mb_substr((string) ($row['title'] ?? ''), 0, 40),
            ));
        }
    }

    private function isWithinPreferredHour(): bool
    {
        $hour = (int) $this->nowKolkata()->format('H');
        return $hour === 0;
    }

    private function nowKolkata(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    }
}

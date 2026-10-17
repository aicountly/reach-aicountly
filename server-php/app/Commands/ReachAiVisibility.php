<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:ai-visibility <status|run|summary>`
 *
 *   status              which assistants will run, and why the others are skipped
 *   run [--sync]        start a manual pass now (queued for the worker, or
 *                       executed in this process with --sync); respects the
 *                       daily prompt cap
 *   summary [--days=7]  mention rate / share of voice per assistant
 *
 * The daily pass itself is scheduled by reach:schedule (05:00 IST).
 */
class ReachAiVisibility extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:ai-visibility';
    protected $description = 'AI visibility (GEO): assistant status, run a pass now, or print the summary.';
    protected $usage       = 'reach:ai-visibility <status|run|summary> [--sync] [--days=7]';

    public function run(array $params): int
    {
        $action = strtolower(trim((string) ($params[0] ?? 'status')));
        $svc    = new AiVisibilityService();

        $out = match ($action) {
            'status'  => $svc->providers(),
            'run'     => $svc->startBatch('manual', null, $this->sparkOption('sync', $params) === '1'),
            'summary' => $svc->compactSummary(max(1, (int) ($this->sparkOption('days', $params, '7') ?? '7'))),
            default   => null,
        };
        if ($out === null) {
            CLI::error('Unknown action. ' . $this->usage);
            return 1;
        }
        CLI::write((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}

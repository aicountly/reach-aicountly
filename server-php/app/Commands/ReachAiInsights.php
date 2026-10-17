<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Intelligence\Insights\InsightsDigestBuilder;
use App\Libraries\Intelligence\Insights\InsightsService;
use App\Libraries\Intelligence\Insights\InsightsUnavailableException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:ai-insights <digest|run|latest>`
 *
 *   digest   print the data digest the model would receive (no AI call)
 *   run      run AI Insights now and store the report
 *   latest   print the latest completed report
 *
 * The weekly run is scheduled by reach:schedule (Sunday 16:00 IST).
 */
class ReachAiInsights extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:ai-insights';
    protected $description = 'AI Insights: print the data digest, run a report now, or show the latest.';
    protected $usage       = 'reach:ai-insights <digest|run|latest>';

    public function run(array $params): int
    {
        $action = strtolower(trim((string) ($params[0] ?? 'latest')));
        try {
            $out = match ($action) {
                'digest' => (new InsightsDigestBuilder())->build(),
                'run'    => (new InsightsService())->run('manual'),
                'latest' => (new InsightsService())->latest() ?? ['message' => 'No completed AI Insights report yet.'],
                default  => null,
            };
        } catch (InsightsUnavailableException $e) {
            CLI::error($e->getMessage());
            return 1;
        }
        if ($out === null) {
            CLI::error('Unknown action. ' . $this->usage);
            return 1;
        }
        CLI::write((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}

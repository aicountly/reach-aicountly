<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Bot\Plan\BotPlanRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:bot-plan [--refresh] [--no-tune]` — run the bot planner by
 * hand (the scheduler runs it as job reach.bot_plan): snapshot actuals,
 * review + self-tune, regenerate the plans. --refresh only regenerates the
 * plans; --no-tune reviews without self-tuning.
 */
class ReachBotPlan extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:bot-plan';
    protected $description = 'Bot Plan & Performance: snapshot actuals, review + self-tune, regenerate plans.';
    protected $usage       = 'reach:bot-plan [--refresh] [--no-tune]';

    public function run(array $params): int
    {
        $refresh = $this->sparkFlag('refresh', $params);
        $noTune  = $this->sparkFlag('no-tune', $params);
        $out     = (new BotPlanRunner())->run($refresh ? 'refresh' : 'daily', null, ! $noTune);
        CLI::write((string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}

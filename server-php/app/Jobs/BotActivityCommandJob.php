<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Bot\BotActivityRegistry;
use App\Libraries\Bot\SparkCommandRunner;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * "Run now" for a cron command (Bot → Activities). Payload: {activity_key}.
 *
 * The command and its arguments come from BotActivityRegistry — never from
 * the payload — so a queued job can only ever start a command a cron line
 * already runs. A non-zero exit fails the job (with the output tail) rather
 * than reporting success.
 */
class BotActivityCommandJob implements JobHandlerInterface
{
    private static ?SparkCommandRunner $runner = null;

    /** @internal tests */
    public static function useRunner(?SparkCommandRunner $runner): void
    {
        self::$runner = $runner;
    }

    public function handle(array $payload, JobContext $ctx): array
    {
        $key     = (string) ($payload['activity_key'] ?? '');
        $command = BotActivityRegistry::commandFor($key);
        if ($command === null) {
            throw new \RuntimeException("Unknown bot activity '{$key}': nothing was run.");
        }

        $result = (self::$runner ?? new SparkCommandRunner())->run($command['command'], $command['args']);
        $line   = 'php spark ' . trim($command['command'] . ' ' . implode(' ', $command['args']));

        if ($result['timed_out']) {
            throw new \RuntimeException("{$line} did not finish within " . SparkCommandRunner::DEFAULT_TIMEOUT_SECS . " s and was stopped. Output: " . mb_substr($result['output'], -1500));
        }
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException("{$line} exited with code {$result['exit_code']}. Output: " . mb_substr($result['output'], -1500));
        }

        return [
            'activity_key' => $key,
            'command'      => $line,
            'exit_code'    => 0,
            'duration_ms'  => $result['duration_ms'],
            'output'       => $result['output'],
        ];
    }
}

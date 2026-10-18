<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

/**
 * Runs `php spark <command> <args…>` as a child process — exactly what the
 * cron line runs — and returns its exit code and the tail of its output.
 *
 * A child process (not CodeIgniter's in-process command()) so the command
 * sees its own argv: in-process, a command's option parsing would also read
 * the worker's own `--limit=` / `--queue=` from $_SERVER['argv'].
 *
 * No shell is involved (proc_open with an argument list) and only commands
 * from BotActivityRegistry reach here.
 */
class SparkCommandRunner
{
    public const DEFAULT_TIMEOUT_SECS = 240;
    private const OUTPUT_TAIL = 4000;

    /**
     * @param list<string> $args
     * @return array{exit_code: int, output: string, timed_out: bool, duration_ms: int}
     */
    public function run(string $command, array $args = [], int $timeoutSecs = self::DEFAULT_TIMEOUT_SECS): array
    {
        if (! preg_match('/^[a-z][a-z0-9-]*:[a-z0-9-]+$/', $command)) {
            throw new \InvalidArgumentException("Not a spark command name: {$command}");
        }
        if (! function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is disabled on this server, so a spark command cannot be started from the worker. Run it from the cPanel terminal instead.');
        }

        $php   = trim((string) (getenv('REACH_PHP_BINARY') ?: '')) ?: PHP_BINARY;
        $spark = rtrim(ROOTPATH, '/') . '/spark';
        $argv  = array_merge([$php, $spark, $command], $args);

        $started = hrtime(true);
        $proc    = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
        if (! is_resource($proc)) {
            throw new \RuntimeException("Could not start php spark {$command}.");
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out      = '';
        $timedOut = false;
        $deadline = microtime(true) + max(5, $timeoutSecs);
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            if (strlen($out) > self::OUTPUT_TAIL * 4) {
                $out = substr($out, -self::OUTPUT_TAIL * 2);
            }
            $status = proc_get_status($proc);
            if (! $status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($proc);
                $exit = 124;
                break;
            }
            usleep(100_000);
        }
        $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [
            'exit_code'   => $exit,
            'output'      => mb_substr(trim($out), -self::OUTPUT_TAIL),
            'timed_out'   => $timedOut,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
    }
}

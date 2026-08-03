<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use CodeIgniter\CLI\CLI;

/**
 * CI4 Spark sometimes drops --foo=bar from CLI::getOption when the command
 * does not declare $options. Parse argv as a durable fallback.
 */
trait ParsesSparkOptions
{
    /**
     * @param array<string,mixed> $params
     */
    protected function sparkOption(string $name, array $params = [], ?string $default = null): ?string
    {
        $fromCli = CLI::getOption($name);
        if (is_string($fromCli) && $fromCli !== '') {
            return $fromCli;
        }
        // Flag present without value (e.g. --generate)
        if ($fromCli !== null && ($fromCli === true || $fromCli === '')) {
            return '1';
        }

        if (array_key_exists($name, $params)) {
            $p = $params[$name];
            if (is_string($p) && $p !== '') {
                return $p;
            }
            if ($p === null || $p === true || $p === '') {
                return '1';
            }
        }

        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $i => $arg) {
            if (! is_string($arg)) {
                continue;
            }
            if (preg_match('/^--' . preg_quote($name, '/') . '=(.*)$/', $arg, $m) === 1) {
                return $m[1];
            }
            if ($arg === '--' . $name) {
                $next = $argv[$i + 1] ?? null;
                if (is_string($next) && $next !== '' && ! str_starts_with($next, '-')) {
                    return $next;
                }

                return '1';
            }
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $params
     */
    protected function sparkFlag(string $name, array $params = []): bool
    {
        return $this->sparkOption($name, $params) !== null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Set an env var for the duration of a callback in all three places CI4's env()
 * reads ($_ENV first, then $_SERVER, then getenv()), and restore them after.
 */
trait EnvOverride
{
    /** @param callable():mixed $fn */
    private function withEnv(string $key, ?string $value, callable $fn): mixed
    {
        $had = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null, array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null, getenv($key)];
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
        try {
            return $fn();
        } finally {
            [$inEnv, $envV, $inServer, $serverV, $getenvV] = $had;
            if ($inEnv) { $_ENV[$key] = $envV; } else { unset($_ENV[$key]); }
            if ($inServer) { $_SERVER[$key] = $serverV; } else { unset($_SERVER[$key]); }
            if ($getenvV === false) { putenv($key); } else { putenv("{$key}={$getenvV}"); }
        }
    }
}

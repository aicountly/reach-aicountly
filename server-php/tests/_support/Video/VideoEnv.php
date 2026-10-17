<?php

declare(strict_types=1);

namespace Tests\Support\Video;

use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\VideoProviders;

/**
 * Sets env-backed settings for a test and restores everything afterwards
 * (env, ReachConfig cache, provider overrides, the production guard).
 */
trait VideoEnv
{
    /** @var array<string, string|null> */
    private array $videoEnvBackup = [];

    /** @param array<string, string|null> $vars */
    protected function setVideoEnv(array $vars): void
    {
        foreach ($vars as $k => $v) {
            if (! array_key_exists($k, $this->videoEnvBackup)) {
                $this->videoEnvBackup[$k] = $_ENV[$k] ?? null;
            }
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
        ReachConfig::flush();
    }

    protected function restoreVideoEnv(): void
    {
        foreach ($this->videoEnvBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
        $this->videoEnvBackup = [];
        ReachConfig::flush();
        VideoProviders::reset();
        ProviderGuard::forceProduction(null);
    }
}

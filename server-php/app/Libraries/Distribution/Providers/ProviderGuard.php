<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

/**
 * Production honesty for distribution providers.
 *
 * Mock senders/publishers exist so development and tests can exercise the
 * dispatch pipeline. In production they must refuse: a mock "accepting" a
 * message would mark campaigns sent and posts posted that never reached
 * anyone. CodeIgniter treats an unset CI_ENVIRONMENT as production, and so
 * does this guard.
 */
final class ProviderGuard
{
    private static ?bool $forced = null;

    public static function isProduction(): bool
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        $env = defined('ENVIRONMENT') ? (string) ENVIRONMENT : (string) (getenv('CI_ENVIRONMENT') ?: 'production');

        return strtolower(trim($env)) === 'production';
    }

    /**
     * Throw when a mock is about to stand in for a real provider in production.
     *
     * @param string $channel     e.g. "Email", "SMS", "WhatsApp", "Social"
     * @param string $requirement what an operator must configure
     */
    public static function refuseMockInProduction(string $channel, string $requirement): void
    {
        if (self::isProduction()) {
            throw new ProviderNotConfiguredException(
                "{$channel} provider not configured: {$requirement}. Nothing was sent."
            );
        }
    }

    /** @internal Tests only: pretend to be (or not be) production. Pass null to reset. */
    public static function forceProduction(?bool $production): void
    {
        self::$forced = $production;
    }
}

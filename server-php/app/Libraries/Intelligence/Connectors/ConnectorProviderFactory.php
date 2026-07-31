<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Connectors;

class ConnectorProviderFactory
{
    private static bool $useMocks = false;

    public static function useMocks(bool $value = true): void
    {
        self::$useMocks = $value;
    }

    /**
     * Returns the live Google connector whenever real credentials are present, and
     * the mock only when explicitly requested via useMocks() or when
     * SEARCH_CONSOLE_USE_MOCK=true. A misconfigured live connector is returned as-is
     * so callers surface an honest "misconfigured" state instead of mock numbers.
     */
    public static function searchConsole(): SearchConsoleConnectorInterface
    {
        if (self::$useMocks || self::envBool('SEARCH_CONSOLE_USE_MOCK')) {
            return new MockSearchConsoleConnector(enabled: true);
        }

        return new GoogleSearchConsoleConnector();
    }

    /**
     * Returns the live GA4 connector whenever GA4_ENABLED is set, and the mock only
     * when explicitly requested via useMocks() or CONTENT_ANALYTICS_USE_MOCK=true.
     *
     * Previously this always returned MockContentAnalyticsConnector — a real,
     * working GA4 client (Ga4AnalyticsClient / GoogleAnalyticsContentConnector)
     * existed but was never wired in, so a configured GA4 property never actually
     * reached dashboards; they silently consumed mock rows regardless of
     * configuration.
     */
    public static function contentAnalytics(): ContentAnalyticsConnectorInterface
    {
        if (self::$useMocks || self::envBool('CONTENT_ANALYTICS_USE_MOCK')) {
            return new MockContentAnalyticsConnector(enabled: true);
        }

        return new GoogleAnalyticsContentConnector();
    }

    public static function indexNow(): IndexNowConnectorInterface
    {
        if (self::$useMocks) {
            return new MockIndexNowConnector(enabled: true);
        }

        $enabled = (bool) (getenv('INDEXNOW_ENABLED') ?: ($_ENV['INDEXNOW_ENABLED'] ?? false));
        return new MockIndexNowConnector(enabled: $enabled);
    }

    private static function envBool(string $key): bool
    {
        // Prefer $_ENV over getenv(): dotenv / putenv() can leave a stale
        // "false" in the process environment that would otherwise shadow an
        // intentional $_ENV override (tests and request-scoped flips).
        if (array_key_exists($key, $_ENV)) {
            return filter_var((string) $_ENV[$key], FILTER_VALIDATE_BOOL);
        }

        $value = getenv($key);
        if ($value === false || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}

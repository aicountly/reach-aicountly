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

    public static function contentAnalytics(): ContentAnalyticsConnectorInterface
    {
        if (self::$useMocks) {
            return new MockContentAnalyticsConnector(enabled: true);
        }

        $enabled = (bool) (getenv('CONTENT_ANALYTICS_ENABLED') ?: ($_ENV['CONTENT_ANALYTICS_ENABLED'] ?? false));
        return new MockContentAnalyticsConnector(enabled: $enabled);
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
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? '';
        }

        return filter_var(is_string($value) ? $value : '', FILTER_VALIDATE_BOOL);
    }
}

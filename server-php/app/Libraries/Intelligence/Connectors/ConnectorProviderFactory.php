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

    public static function searchConsole(): SearchConsoleConnectorInterface
    {
        if (self::$useMocks) {
            return new MockSearchConsoleConnector(enabled: true);
        }

        $enabled = (bool) (getenv('SEARCH_CONSOLE_ENABLED') ?: ($_ENV['SEARCH_CONSOLE_ENABLED'] ?? false));
        return new MockSearchConsoleConnector(enabled: $enabled);
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
}

<?php

namespace App\Libraries\Community;

/**
 * Creates the appropriate community publisher for the current environment.
 *
 * Returns MockCommunityPublisher in test/mock environments,
 * CommunityPublicSitePublisher in production.
 */
class CommunityPublisherFactory
{
    private static ?CommunityPublisherInterface $override = null;

    /**
     * Override the publisher (used in tests).
     */
    public static function setOverride(?CommunityPublisherInterface $publisher): void
    {
        self::$override = $publisher;
    }

    public static function create(): CommunityPublisherInterface
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $env = strtolower($_ENV['APP_ENV'] ?? 'production');
        if ($env === 'testing' || self::mockForced()) {
            return new MockCommunityPublisher();
        }

        return new CommunityPublicSitePublisher();
    }

    /**
     * `REACH_PUB_COMMUNITY_MOCK=false` in a .env file is the string "false",
     * which is truthy — the old `!empty()` check therefore swapped production
     * in the mock publisher for anyone who wrote the flag out explicitly, and
     * every publish silently succeeded without touching aicountly.com.
     */
    public static function mockForced(): bool
    {
        $raw = $_ENV['REACH_PUB_COMMUNITY_MOCK'] ?? getenv('REACH_PUB_COMMUNITY_MOCK');
        if ($raw === false || $raw === null || $raw === '') {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL);
    }
}

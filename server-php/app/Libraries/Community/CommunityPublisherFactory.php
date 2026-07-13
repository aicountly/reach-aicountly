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
        if ($env === 'testing' || !empty($_ENV['REACH_PUB_COMMUNITY_MOCK'])) {
            return new MockCommunityPublisher();
        }

        return new CommunityPublicSitePublisher();
    }
}

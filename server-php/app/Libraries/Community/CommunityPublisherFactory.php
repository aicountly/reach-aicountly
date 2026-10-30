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

        // The mock reports success with a canonical_url that does not exist, so it
        // must never stand in for the real publisher in production. Two guards:
        // the flag is parsed as a boolean — !empty() made the string 'false' turn
        // the mock ON, and that is how the variable is written in the docs — and
        // production ignores the flag entirely.
        $mockRequested = filter_var($_ENV['REACH_PUB_COMMUNITY_MOCK'] ?? false, FILTER_VALIDATE_BOOL);
        if ($env === 'testing' || ($mockRequested && $env !== 'production')) {
            return new MockCommunityPublisher();
        }

        return new CommunityPublicSitePublisher();
    }
}

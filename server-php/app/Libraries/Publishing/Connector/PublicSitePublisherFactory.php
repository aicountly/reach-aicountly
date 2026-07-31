<?php

namespace App\Libraries\Publishing\Connector;

/**
 * Phase 4 — Factory that returns the correct publisher based on environment.
 *
 * REACH_PUB_MOCK=true (or test environment) → MockPublicSitePublisher
 * Otherwise → AicountlyPublicSitePublisher
 *
 * The mock instance is memoized so createDraft/schedule and a later
 * getVerification() share checksum bookkeeping within one PHP process.
 */
class PublicSitePublisherFactory
{
    private static ?MockPublicSitePublisher $mockSingleton = null;

    public static function make(): PublicSitePublisherInterface
    {
        $forceMock = strtolower((string) ($_ENV['REACH_PUB_MOCK'] ?? 'false'));

        if ($forceMock === 'true') {
            return self::mock();
        }

        $env = strtolower((string) ($_ENV['CI_ENVIRONMENT'] ?? 'production'));
        if ($env === 'testing') {
            return self::mock();
        }

        return new AicountlyPublicSitePublisher();
    }

    /**
     * Shared mock publisher for the current process. Tests that construct
     * MockPublicSitePublisher directly are unaffected.
     */
    public static function mock(): MockPublicSitePublisher
    {
        return self::$mockSingleton ??= new MockPublicSitePublisher();
    }

    /** @internal Reset between Feature tests when isolation is required. */
    public static function resetMock(): void
    {
        if (self::$mockSingleton !== null) {
            self::$mockSingleton->reset();
        }
        self::$mockSingleton = null;
    }
}

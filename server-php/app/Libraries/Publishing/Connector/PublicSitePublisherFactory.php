<?php

namespace App\Libraries\Publishing\Connector;

/**
 * Phase 4 — Factory that returns the correct publisher based on environment.
 *
 * REACH_PUB_MOCK=true (or test environment) → MockPublicSitePublisher
 * Otherwise → AicountlyPublicSitePublisher
 */
class PublicSitePublisherFactory
{
    public static function make(): PublicSitePublisherInterface
    {
        $forceMock = strtolower((string) ($_ENV['REACH_PUB_MOCK'] ?? 'false'));

        if ($forceMock === 'true') {
            return new MockPublicSitePublisher();
        }

        $env = strtolower((string) ($_ENV['CI_ENVIRONMENT'] ?? 'production'));
        if ($env === 'testing') {
            return new MockPublicSitePublisher();
        }

        return new AicountlyPublicSitePublisher();
    }
}

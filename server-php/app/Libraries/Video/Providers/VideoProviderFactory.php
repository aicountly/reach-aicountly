<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

/**
 * Providers for the legacy, manually driven project workflow (projects →
 * render jobs → publications).
 *
 * Real rendering and YouTube publishing now happen only in the automated
 * pipeline (App\Libraries\Video\Automation: Shotstack + YouTube Data API).
 * This factory therefore hands out the deterministic mocks for development
 * and tests, and refuses in production instead of pretending to render or
 * publish (the mocks' constructors throw there too).
 */
class VideoProviderFactory
{
    public static function renderProvider(): RenderProviderInterface
    {
        return self::makeRenderProvider();
    }

    public static function makeRenderProvider(): RenderProviderInterface
    {
        return new MockRenderProvider();
    }

    public static function makeYouTubePublisher(): YouTubePublisherInterface
    {
        return new MockYouTubePublisher();
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

class VideoProviderFactory
{
    /**
     * Returns a RenderProviderInterface implementation.
     *
     * Selection order:
     *   1. CI_ENVIRONMENT === 'testing'          → MockRenderProvider
     *   2. VIDEO_RENDER_PROVIDER === 'mock'       → MockRenderProvider
     *   3. VIDEO_RENDER_PROVIDER === 'production' → ProductionRenderAdapter (CP7 skeleton)
     *   4. default                                → MockRenderProvider (safe default)
     */
    public static function renderProvider(): RenderProviderInterface
    {
        return self::makeRenderProvider();
    }

    public static function makeRenderProvider(): RenderProviderInterface
    {
        if (ENVIRONMENT === 'testing') {
            return new MockRenderProvider();
        }

        $provider = env('VIDEO_RENDER_PROVIDER', 'mock');

        if ($provider === 'production') {
            return new ProductionRenderAdapter();
        }

        return new MockRenderProvider();
    }

    /**
     * Returns a YouTubePublisherInterface implementation.
     *
     * Selection order:
     *   1. CI_ENVIRONMENT === 'testing'           → MockYouTubePublisher
     *   2. YOUTUBE_PUBLISHING_ENABLED !== 'true'  → MockYouTubePublisher
     *   3. default                                → MockYouTubePublisher (safe default until CP7)
     */
    public static function makeYouTubePublisher(): YouTubePublisherInterface
    {
        if (ENVIRONMENT === 'testing') {
            return new MockYouTubePublisher();
        }

        $enabled = env('YOUTUBE_PUBLISHING_ENABLED', 'false');
        if ($enabled !== 'true') {
            return new MockYouTubePublisher();
        }

        // Live YouTubePublisher will be added in CP8.
        // Until then, mock is always returned.
        return new MockYouTubePublisher();
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Video\Automation\Http\CurlTransport;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\Render\MockRenderClient;
use App\Libraries\Video\Automation\Render\RenderClient;
use App\Libraries\Video\Automation\Render\ShotstackClient;
use App\Libraries\Video\Automation\Tts\MockTtsClient;
use App\Libraries\Video\Automation\Tts\OpenAiTtsClient;
use App\Libraries\Video\Automation\Tts\TtsClient;
use App\Libraries\Video\Automation\Visuals\MockVisualSource;
use App\Libraries\Video\Automation\Visuals\OpenAiStillSource;
use App\Libraries\Video\Automation\Visuals\StillImageSource;
use App\Libraries\Video\Automation\Visuals\ScreenshotSource;
use App\Libraries\Video\Automation\Visuals\WorkerScreenshotSource;
use App\Libraries\Video\Automation\YouTube\MockYouTubeClient;
use App\Libraries\Video\Automation\YouTube\YouTubeClient;
use App\Libraries\Video\Automation\YouTube\YouTubeDataClient;
use App\Libraries\Video\Automation\YouTube\YouTubeOAuth;

/**
 * Where the video pipeline gets its providers.
 *
 * Real clients by default: OpenAI TTS, Shotstack, YouTube Data API v3, the
 * Worker screenshot client and DALL·E. VIDEO_PIPELINE_MOCKS=1 swaps in the
 * mocks for local development — every mock refuses to construct in
 * production, so a mis-set flag blocks the pipeline instead of "publishing"
 * a mock video. Tests replace individual providers with override().
 */
final class VideoProviders
{
    /** @var array<string, object> */
    private static array $overrides = [];

    public static function http(): HttpTransport
    {
        return self::$overrides['http'] ?? new CurlTransport();
    }

    public static function tts(): TtsClient
    {
        return self::$overrides['tts'] ?? (self::mocks() ? new MockTtsClient() : new OpenAiTtsClient(self::http()));
    }

    public static function renderer(): RenderClient
    {
        return self::$overrides['renderer'] ?? (self::mocks() ? new MockRenderClient() : new ShotstackClient(self::http()));
    }

    public static function youtube(): YouTubeClient
    {
        return self::$overrides['youtube'] ?? (self::mocks() ? new MockYouTubeClient() : new YouTubeDataClient(self::http(), self::oauth()));
    }

    public static function oauth(): YouTubeOAuth
    {
        return self::$overrides['oauth'] ?? new YouTubeOAuth(self::http());
    }

    public static function screenshots(): ScreenshotSource
    {
        return self::$overrides['screenshots'] ?? (self::mocks() ? new MockVisualSource() : new WorkerScreenshotSource(self::http()));
    }

    public static function stills(): StillImageSource
    {
        return self::$overrides['stills'] ?? (self::mocks() ? new MockVisualSource() : new OpenAiStillSource());
    }

    public static function vetting(): ContentVettingService
    {
        return self::$overrides['vetting'] ?? new ContentVettingService();
    }

    /** Whether the development mocks are selected (never honoured in production). */
    public static function mocks(): bool
    {
        $flag = $_ENV['VIDEO_PIPELINE_MOCKS'] ?? $_SERVER['VIDEO_PIPELINE_MOCKS'] ?? getenv('VIDEO_PIPELINE_MOCKS');
        return in_array(strtolower(trim((string) $flag)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Throw when a mock is about to stand in for a real provider in production. */
    public static function refuseMockInProduction(string $what): void
    {
        if (ProviderGuard::isProduction()) {
            throw VideoProviderException::notConfigured("Mock {$what} refused in production: configure the real provider. Nothing was rendered or published.");
        }
    }

    /** @internal tests */
    public static function override(string $name, object $impl): void
    {
        self::$overrides[$name] = $impl;
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$overrides = [];
    }
}

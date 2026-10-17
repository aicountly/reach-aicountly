<?php

declare(strict_types=1);

namespace Tests\Unit\Video\Automation;

use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Media\VideoMediaStore;
use App\Libraries\Video\Automation\Render\MockRenderClient;
use App\Libraries\Video\Automation\Render\ShotstackClient;
use App\Libraries\Video\Automation\Tts\MockTtsClient;
use App\Libraries\Video\Automation\Tts\OpenAiTtsClient;
use App\Libraries\Video\Automation\VideoProviderException;
use App\Libraries\Video\Automation\VideoProviders;
use App\Libraries\Video\Automation\Visuals\MockVisualSource;
use App\Libraries\Video\Automation\YouTube\MockYouTubeClient;
use App\Libraries\Video\Automation\YouTube\YouTubeDataClient;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\VideoEnv;

/**
 * Mocks never stand in for a real provider in production.
 *
 * @covers \App\Libraries\Video\Automation\VideoProviders
 * @covers \App\Libraries\Media\VideoMediaStore
 */
final class VideoProvidersProductionGuardTest extends CIUnitTestCase
{
    use VideoEnv;

    protected function tearDown(): void
    {
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    public function testRealProvidersAreTheDefault(): void
    {
        $this->setVideoEnv(['VIDEO_PIPELINE_MOCKS' => null]);
        $this->assertFalse(VideoProviders::mocks());
        $this->assertInstanceOf(OpenAiTtsClient::class, VideoProviders::tts());
        $this->assertInstanceOf(ShotstackClient::class, VideoProviders::renderer());
        $this->assertInstanceOf(YouTubeDataClient::class, VideoProviders::youtube());
    }

    public function testMocksAreAvailableOutsideProduction(): void
    {
        $this->setVideoEnv(['VIDEO_PIPELINE_MOCKS' => '1']);
        ProviderGuard::forceProduction(false);
        $this->assertInstanceOf(MockTtsClient::class, VideoProviders::tts());
        $this->assertInstanceOf(MockRenderClient::class, VideoProviders::renderer());
        $this->assertInstanceOf(MockYouTubeClient::class, VideoProviders::youtube());
        $this->assertInstanceOf(MockVisualSource::class, VideoProviders::stills());
    }

    public function testEveryMockRefusesInProduction(): void
    {
        $this->setVideoEnv(['VIDEO_PIPELINE_MOCKS' => '1']);
        ProviderGuard::forceProduction(true);
        foreach (['tts', 'renderer', 'youtube', 'stills', 'screenshots'] as $provider) {
            try {
                VideoProviders::{$provider}();
                $this->fail("mock {$provider} constructed in production");
            } catch (VideoProviderException $e) {
                $this->assertSame(VideoProviderException::NOT_CONFIGURED, $e->kind, $provider);
                $this->assertStringContainsString('refused in production', $e->getMessage());
            }
        }
    }

    public function testMediaUrlsAreSignedExpiringAndFailClosedWithoutAKey(): void
    {
        $this->setVideoEnv(['MEDIA_SIGNING_KEY' => 'media-key-for-tests', 'REACH_APP_URL' => 'https://reach.aicountly.org', 'REACH_PUBLIC_BASE_URL' => null]);
        $uuid = '0f8b6a2e-3c1d-4e5f-9a7b-1c2d3e4f5a6b';
        $exp  = time() + 600;
        $sig  = VideoMediaStore::signature($uuid, $exp);
        $this->assertTrue(VideoMediaStore::verify($uuid, $exp, $sig));
        $this->assertFalse(VideoMediaStore::verify($uuid, $exp + 1, $sig), 'expiry is signed');
        $this->assertFalse(VideoMediaStore::verify('0f8b6a2e-3c1d-4e5f-9a7b-000000000000', $exp, $sig));
        $this->assertFalse(VideoMediaStore::verify($uuid, time() - 1, VideoMediaStore::signature($uuid, time() - 1)), 'expired');

        $this->setVideoEnv(['MEDIA_SIGNING_KEY' => null]);
        $this->assertFalse(VideoMediaStore::verify($uuid, $exp, $sig));
    }
}

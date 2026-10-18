<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\CampaignReadinessService;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiPlatforms;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\VideoProviders;
use App\Libraries\Video\Automation\YouTube\YouTubePublisherMode;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * Readiness of the social channels and of the YouTube path when they are
 * backed by social-api.ai, plus the settings that drive them.
 *
 * @covers \App\Libraries\Distribution\CampaignReadinessService
 * @covers \App\Libraries\Distribution\Providers\ChannelProviderFactory
 * @covers \App\Libraries\Video\Automation\YouTube\YouTubePublisherMode
 * @covers \App\Libraries\Settings\ReachConfig
 *
 * @internal
 */
final class SocialApiReadinessTest extends CIUnitTestCase
{
    use VideoEnv;

    private FakeHttpTransport $http;

    protected function setUp(): void
    {
        parent::setUp();
        $env = ['SOCIALAPI_API_KEY' => 'sapi_key_readiness_secret', 'SOCIALAPI_BASE_URL' => null, 'SOCIAL_PROVIDER' => null, 'VIDEO_YOUTUBE_PUBLISHER' => null];
        foreach (SocialApiPlatforms::PLATFORMS as $p) {
            $env['SOCIALAPI_ACCOUNT_' . strtoupper($p)] = null;
        }
        $this->setVideoEnv($env);
        SocialApiAccounts::forget();
        $this->http = (new FakeHttpTransport())->on('GET', '#^https://api\.social-api\.ai/v1/accounts$#', FakeHttpTransport::json(200, ['accounts' => [
            ['id' => 'acc_yt', 'name' => 'AICOUNTLY', 'platform' => 'youtube', 'status' => 'active'],
            ['id' => 'acc_fb_1', 'platform' => 'facebook', 'status' => 'active'],
            ['id' => 'acc_fb_2', 'platform' => 'facebook', 'status' => 'active'],
            ['id' => 'acc_x', 'platform' => 'twitter', 'status' => 'reauth_required'],
        ]]));
        VideoProviders::override('http', $this->http);
    }

    protected function tearDown(): void
    {
        SocialApiAccounts::forget();
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function social(string $key, string $label): array
    {
        $m = new \ReflectionMethod(CampaignReadinessService::class, 'social');
        return $m->invoke(new CampaignReadinessService(), $key, $label);
    }

    public function testReadyOnlyWhereAnAccountResolves(): void
    {
        $yt = $this->social('youtube', 'YouTube');
        $this->assertSame('ready', $yt['state']);
        $this->assertSame('social_api', $yt['provider']);
        $this->assertSame('acc_yt', $yt['account']['id']);
        $this->assertSame('social_api', $yt['youtube_publisher']);
        $this->assertStringContainsString('through social-api.ai (video.youtube.publisher = social_api)', implode(' ', $yt['reasons']));

        $fb = $this->social('facebook', 'Facebook');
        $this->assertSame('blocked', $fb['state']);
        $this->assertStringContainsString('2 active Facebook accounts', $fb['reasons'][0]);
        $this->assertFalse(array_column($fb['checks'], 'ok', 'key')['social.socialapi.account.facebook']);

        $x = $this->social('twitter', 'X / Twitter');
        $this->assertSame('blocked', $x['state']);
        $this->assertStringContainsString('(reauth_required)', $x['reasons'][0]);

        $li = $this->social('linkedin', 'LinkedIn');
        $this->assertSame('blocked', $li['state']);
        $this->assertStringContainsString('No active LinkedIn account', $li['reasons'][0]);
        $this->assertStringContainsString('reach:integrations-check --only=social_api', implode(' ', $li['manual']));

        $this->assertCount(1, $this->http->to('#/accounts#'), 'one cached account list for every channel');
        $this->assertStringNotContainsString('sapi_key_readiness_secret', json_encode([$yt, $fb, $x, $li]));
        $this->assertStringNotContainsString('API docs required', json_encode([$yt, $fb, $x, $li]));
    }

    public function testAnOverrideResolvesTheAmbiguity(): void
    {
        $this->setVideoEnv(['SOCIALAPI_ACCOUNT_FACEBOOK' => 'acc_fb_2']);
        $fb = $this->social('facebook', 'Facebook');
        $this->assertSame('ready', $fb['state']);
        $this->assertSame('setting', $fb['account']['source']);
        $this->assertTrue(ChannelProviderFactory::socialPublisherReadyFor('facebook'));
        $this->assertFalse(ChannelProviderFactory::socialPublisherReadyFor('linkedin'));
    }

    public function testWithoutAKeyTheChannelIsNotConfigured(): void
    {
        $this->setVideoEnv(['SOCIALAPI_API_KEY' => null]);
        ProviderGuard::forceProduction(true);
        $li = $this->social('linkedin', 'LinkedIn');
        $this->assertSame('not_configured', $li['state']);
        $this->assertSame('mock_social', $li['provider']);
        $this->assertStringContainsString('set social.socialapi.api_key', $li['reasons'][0]);
        $this->assertSame([], $this->http->requests);
    }

    public function testARefusedKeyBlocksWithTheReason(): void
    {
        $denied = (new FakeHttpTransport())->on('GET', '#/accounts$#', FakeHttpTransport::json(403, ['error' => ['message' => 'forbidden']]));
        VideoProviders::override('http', $denied);
        $yt = $this->social('youtube', 'YouTube');
        $this->assertSame('blocked', $yt['state']);
        $this->assertStringContainsString('the API key was refused', $yt['reasons'][0]);
        $this->assertSame('blocked', $this->social('linkedin', 'LinkedIn')['state']);
        $this->assertCount(1, $denied->requests, 'a failed listing is not repeated for every channel in one request');
    }

    public function testTheYouTubePathFollowsTheSetting(): void
    {
        $this->assertSame(YouTubePublisherMode::SOCIAL_API, YouTubePublisherMode::current(), 'social_api is the default');
        $d = YouTubePublisherMode::describe(['connected' => false, 'client_configured' => false]);
        $this->assertTrue($d['ready']);
        $this->assertSame('acc_yt', $d['account']['id']);

        $this->setVideoEnv(['VIDEO_YOUTUBE_PUBLISHER' => 'direct']);
        $d = YouTubePublisherMode::describe(['connected' => false, 'client_configured' => true]);
        $this->assertSame('direct', $d['mode']);
        $this->assertFalse($d['ready']);
        $this->assertStringContainsString('Connect YouTube channel', (string) $d['reason']);
        $this->assertSame('direct', YouTubePublisherMode::forItem(['youtube_publisher' => null]));
        $this->assertSame('social_api', YouTubePublisherMode::forItem(['youtube_publisher' => 'social_api']), 'an item keeps the path it started on');

        $this->setVideoEnv(['VIDEO_YOUTUBE_PUBLISHER' => null, 'SOCIALAPI_API_KEY' => null]);
        $d = YouTubePublisherMode::describe(['connected' => true, 'client_configured' => true]);
        $this->assertFalse($d['ready']);
        $this->assertStringContainsString('social.socialapi.api_key is not set', (string) $d['reason']);
    }

    public function testSettingsRegistryAndValidation(): void
    {
        $reg = ReachConfig::registry();
        $this->assertSame('https://api.social-api.ai/v1', ReachConfig::get('social.socialapi.base_url'));
        $this->assertSame('social-api.ai API key', $reg['social.socialapi.api_key']['label']);
        $this->assertSame(['social_api', 'direct'], $reg['video.youtube.publisher']['options']);

        // one contiguous block
        $keys  = array_keys($reg);
        $mine  = array_values(array_filter($keys, static fn ($k) => str_starts_with($k, 'social.socialapi.') || $k === 'video.youtube.publisher'));
        $first = array_search($mine[0], $keys, true);
        $this->assertSame($mine, array_slice($keys, $first, count($mine)));
        $this->assertCount(2 + 1 + 6 + 6, $mine);

        $this->assertSame('{"youtube":{"privacy":"unlisted"}}', ReachConfig::validate('social.socialapi.platform_data.youtube', ' {"youtube": {"privacy": "unlisted"}} '));
        $this->assertSame('{}', ReachConfig::validate('social.socialapi.platform_data.youtube', '{}'));
        $this->assertSame('', ReachConfig::validate('social.socialapi.platform_data.youtube', ''));
        foreach (['[1,2]', '"str"', '{bad json', '42'] as $bad) {
            try {
                ReachConfig::validate('social.socialapi.platform_data.youtube', $bad);
                $this->fail("{$bad} accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('platform_data.youtube', $e->getMessage());
            }
        }
        $this->assertSame('direct', ReachConfig::validate('video.youtube.publisher', 'direct'));
        $this->expectException(\InvalidArgumentException::class);
        ReachConfig::validate('video.youtube.publisher', 'ftp');
    }

    public function testUrlAndAccountIdFormats(): void
    {
        $this->assertSame('https://api.social-api.ai/v2', ReachConfig::validate('social.socialapi.base_url', 'https://api.social-api.ai/v2'));
        $this->assertSame('acc_123:yt', ReachConfig::validate('social.socialapi.account.youtube', 'acc_123:yt'));
        foreach ([['social.socialapi.base_url', 'http://api.social-api.ai/v1'], ['social.socialapi.base_url', 'not a url'], ['social.socialapi.account.youtube', 'acc 1; DROP']] as [$k, $v]) {
            try {
                ReachConfig::validate($k, $v);
                $this->fail("{$k}={$v} accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

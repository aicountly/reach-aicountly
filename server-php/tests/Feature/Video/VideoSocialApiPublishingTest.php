<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts;
use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Render\RenderClient;
use App\Libraries\Video\Automation\Render\RenderResult;
use App\Libraries\Video\Automation\Tts\MockTtsClient;
use App\Libraries\Video\Automation\VideoPipelineRepository;
use App\Libraries\Video\Automation\VideoProviders;
use App\Libraries\Video\Automation\Visuals\MockVisualSource;
use App\Libraries\Video\Automation\YouTube\MockYouTubeClient;
use Config\Services;
use Tests\Support\ApiTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;
use Tests\Unit\Video\Automation\VideoScriptValidatorTest;

/**
 * video.youtube.publisher = social_api (the default): the rendered MP4 goes
 * to YouTube through social-api.ai, and the item is `published` only once
 * social-api.ai reports the YouTube post published.
 *
 * @covers \App\Libraries\Video\Automation\VideoPipelineRunner
 * @covers \App\Libraries\Video\Automation\YouTube\YouTubePublisherMode
 * @covers \App\Libraries\Video\Automation\VideoPipelineStatus
 *
 * @internal
 */
final class VideoSocialApiPublishingTest extends ApiTestCase
{
    use VideoEnv;

    private const TOKEN = 'test-automation-token-0123456789abcdef';
    private const KEY   = 'sapi_key_video_feature_secret_555';
    private const MP4   = '#^https://cdn\.shotstack\.example/render-[a-z0-9]+\.mp4$#';

    private FakeHttpTransport $http;
    private MockYouTubeClient $youtube;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv([
            'REACH_AUTOMATION_TOKEN'  => self::TOKEN,
            'AI_GEMINI_API_KEY'       => 'gemini-test-key',
            'AI_GEMINI_DEFAULT_MODEL' => 'gemini-test-flash',
            'MEDIA_SIGNING_KEY'       => 'media-signing-key-for-tests',
            'REACH_APP_URL'           => 'https://reach.example.test',
            'VIDEO_MEDIA_STORAGE_PATH' => WRITEPATH . 'uploads/video-media-test/',
            'VIDEO_PIPELINE_MOCKS'    => null,
            'VIDEO_YOUTUBE_PUBLISHER' => null, // default: social_api
            'SOCIALAPI_API_KEY'       => self::KEY,
            'SOCIALAPI_BASE_URL'      => null,
            'SOCIAL_PROVIDER'         => null,
            'SOCIALAPI_ACCOUNT_YOUTUBE' => null,
            'SOCIALAPI_PLATFORM_DATA_YOUTUBE' => '{"youtube":{"privacy_status":"public"}}',
            'YOUTUBE_REFRESH_TOKEN'   => null,
            'YOUTUBE_CLIENT_ID'       => null,
            'YOUTUBE_CLIENT_SECRET'   => null,
        ]);
        SocialApiAccounts::forget();

        $this->youtube = new MockYouTubeClient();
        VideoProviders::override('vetting', new ContentVettingService(static fn (string $url, array $body): array => [
            'status' => 200,
            'body'   => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['decision' => 'approve', 'reasons' => [], 'scores' => [], 'final_json' => '{}'])]]]]]]),
        ]));
        VideoProviders::override('tts', new MockTtsClient());
        VideoProviders::override('renderer', new class () implements RenderClient {
            public function submit(array $edit): string
            {
                return 'r' . substr(md5((string) microtime(true)), 0, 10);
            }

            public function status(string $renderId): RenderResult
            {
                return new RenderResult('done', 'done', "https://cdn.shotstack.example/render-{$renderId}.mp4", null, null, null, 42.0);
            }

            public function name(): string
            {
                return 'test_render';
            }
        });
        VideoProviders::override('youtube', $this->youtube);
        VideoProviders::override('stills', new MockVisualSource());
        VideoProviders::override('screenshots', new MockVisualSource());

        $this->http = (new FakeHttpTransport())
            ->on('GET', '#^https://api\.social-api\.ai/v1/accounts$#', FakeHttpTransport::json(200, [
                ['id' => 'acc_yt', 'name' => 'AICOUNTLY', 'platform' => 'youtube', 'status' => 'active'],
                ['id' => 'acc_ig', 'name' => 'AICOUNTLY', 'platform' => 'instagram', 'status' => 'active'],
            ]))
            ->on('GET', self::MP4, new HttpResponse(200, 'FAKE-MP4-BYTES', ['content-type' => 'video/mp4']))
            ->on('GET', '#/v1/media/upload-url\?#', FakeHttpTransport::json(200, ['media_id' => 'med_yt', 'upload_url' => 'https://uploads.social-api.example/med_yt?X-Amz-Signature=abc']))
            ->on('PUT', '#^https://uploads\.social-api\.example/#', new HttpResponse(200, ''))
            ->on('POST', '#/v1/media/med_yt/verify$#', FakeHttpTransport::json(200, ['status' => 'ready']));
        VideoProviders::override('http', $this->http);
    }

    protected function tearDown(): void
    {
        SocialApiAccounts::forget();
        $this->restoreVideoEnv();
        try {
            \Config\Database::connect()->table('reach_approvals')->where('subject_type', 'video_script')->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function submit(array $payload)
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Automation-Token' => self::TOKEN])->withBodyFormat('json')->call('POST', 'v1/automation/video-scripts', $payload);
    }

    /** @return list<string> */
    private function drain(int $max = 60): array
    {
        $db   = \Config\Database::connect();
        $svc  = Services::jobService();
        $reg  = Services::jobHandlers();
        $seen = [];
        for ($i = 0; $i < $max; $i++) {
            $db->query("UPDATE reach_jobs SET available_at = NOW() - INTERVAL '1 second' WHERE status = 'pending' AND queue = 'video'");
            // The poller skips a post checked in the last 20 s; collapse that wait too.
            $db->query("UPDATE reach_social_posts SET last_polled_at = NOW() - INTERVAL '5 minutes' WHERE status = 'publishing'");
            $row = $svc->reserve('video', 'phpunit');
            if (! $row) {
                break;
            }
            $payload = json_decode((string) $row['payload_json'], true);
            try {
                $result = $reg->execute($row, 'phpunit');
                $svc->markCompleted((int) $row['id'], $result);
                $seen[] = ($payload['step'] ?? $row['job_type']) . ':' . ($result['status'] ?? '');
            } catch (\Throwable $e) {
                $svc->markFailed((int) $row['id'], $e->getMessage());
                $seen[] = ($payload['step'] ?? $row['job_type']) . ':error:' . $e->getMessage();
            }
        }
        return $seen;
    }

    public function testAShortGoesToYouTubeThroughSocialApiAndIsPublishedOnlyWhenItSaysSo(): void
    {
        $this->http
            ->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_yt', 'status' => 'pending']), 1)
            ->on('GET', '#/v1/posts/post_yt$#', FakeHttpTransport::json(200, ['id' => 'post_yt', 'status' => 'processing', 'targets' => [['account_id' => 'acc_yt', 'status' => 'processing']]]), 1)
            ->on('GET', '#/v1/posts/post_yt$#', FakeHttpTransport::json(200, ['id' => 'post_yt', 'status' => 'published', 'targets' => [['account_id' => 'acc_yt', 'status' => 'published', 'url' => 'https://www.youtube.com/shorts/AbCdEf12345']]]));

        $res = $this->submit(VideoScriptValidatorTest::short());
        $res->assertStatus(201);
        $uuid  = json_decode((string) $res->getJSON(), true)['data']['item_uuid'];
        $steps = $this->drain();

        $this->assertSame(['tts:advanced', 'visuals:advanced', 'render_submit:advanced', 'render_poll:advanced',
            'youtube_upload:advanced', 'youtube_upload:advanced', 'youtube_upload:advanced', 'youtube_extras:advanced', 'social:published'], $steps);

        $item = (new VideoPipelineRepository())->findByUuid($uuid);
        $this->assertSame('published', $item['status']);
        $this->assertSame('social_api', $item['youtube_publisher']);
        $this->assertSame('AbCdEf12345', $item['youtube_video_id']);
        $this->assertSame('https://www.youtube.com/shorts/AbCdEf12345', $item['youtube_url']);
        $this->assertSame('skipped', $item['thumbnail_status']);
        $this->assertSame('skipped', $item['captions_status']);
        $this->assertStringContainsString('Published through social-api.ai', implode(' ', $item['warnings_json']));
        $this->assertSame([], $this->youtube->calls, 'the direct uploader is not used');

        $db   = \Config\Database::connect();
        $post = $db->table('reach_social_posts')->where('id', (int) $item['youtube_social_post_id'])->get()->getRowArray();
        $this->assertSame('youtube', $post['channel']);
        $this->assertSame('posted', $post['status']);
        $this->assertStringEndsWith('#Shorts', (string) $post['title']);
        $this->assertSame('https://www.youtube.com/shorts/AbCdEf12345', $post['remote_url']);

        $create = json_decode((string) $this->http->to('#/v1/posts$#', 'POST')[0]['body'], true);
        $this->assertSame($post['title'], $create['title']);
        $this->assertSame($post['content'], $create['text'], 'the YouTube description is the post text');
        $this->assertSame([['account_id' => 'acc_yt']], $create['targets']);
        $this->assertSame([['source_type' => 'media_id', 'media_id' => 'med_yt']], $create['media']);
        $this->assertTrue($create['publish_now']);
        $this->assertSame(['youtube' => ['privacy_status' => 'public']], $create['platform_data']);
        $this->assertSame('FAKE-MP4-BYTES', $this->http->to('#uploads\.social-api#', 'PUT')[0]['body']);
        $this->assertCount(1, $this->http->to('#/v1/posts$#', 'POST'), 'submitted once');

        // The Short is then offered to Instagram (account resolves) and queued for the others.
        $social = $db->table('reach_social_posts')->whereIn('id', $item['social_post_ids_json'])->get()->getResultArray();
        $byChannel = array_column($social, 'status', 'channel');
        $this->assertSame(['instagram' => 'approved', 'facebook' => 'manual_queue', 'linkedin' => 'manual_queue'], $byChannel);
        $this->assertStringContainsString('Watch: https://www.youtube.com/shorts/AbCdEf12345', $social[0]['content']);
        $this->assertSame(1, $db->table('reach_jobs')->where('job_type', 'distribution.dispatch.social')->countAllResults());

        $this->assertStringNotContainsString(self::KEY, json_encode($db->table('reach_social_posts')->get()->getResultArray()));

        $status = json_decode((string) $this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/video/pipeline/status')->getJSON(), true)['data'];
        $this->assertSame('social_api', $status['youtube_path']['mode']);
        $this->assertTrue($status['youtube_path']['ready']);
        $this->assertTrue($status['checks']['social_api_youtube_account']);
        $this->assertArrayNotHasKey('youtube_channel', $status['checks'], 'the OAuth channel is not required on this path');
    }

    public function testADeliveryFailureFailsTheItemWithTheProviderCode(): void
    {
        $this->http
            ->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_bad']), 1)
            ->on('GET', '#/v1/posts/post_bad$#', FakeHttpTransport::json(200, ['id' => 'post_bad', 'status' => 'failed', 'targets' => [['account_id' => 'acc_yt', 'status' => 'failed', 'error' => ['code' => 'publishing.youtube.quota', 'message' => 'Upload quota exceeded']]]]));

        $uuid  = json_decode((string) $this->submit(VideoScriptValidatorTest::short())->getJSON(), true)['data']['item_uuid'];
        $steps = $this->drain();
        $this->assertSame('youtube_upload:failed', end($steps));

        $item = (new VideoPipelineRepository())->findByUuid($uuid);
        $this->assertSame('failed', $item['status']);
        $this->assertSame('youtube_upload', $item['step']);
        $this->assertStringContainsString('publishing.youtube.quota', (string) $item['last_error']);
        $this->assertNull($item['youtube_url']);
        $this->assertNull($item['published_at']);

        // Retry submits a fresh post rather than re-reading the failed one.
        $this->http->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_good']), 1)
            ->on('GET', '#/v1/posts/post_good$#', FakeHttpTransport::json(200, ['id' => 'post_good', 'status' => 'published', 'targets' => [['account_id' => 'acc_yt', 'status' => 'published', 'platform_post_id' => 'ZYXwvu98765']]]));
        $this->withHeaders($this->authAs('super_admin'))->call('POST', 'v1/video/pipeline/' . $uuid . '/retry')->assertStatus(200);
        $this->drain();
        $item = (new VideoPipelineRepository())->findByUuid($uuid);
        $this->assertSame('published', $item['status']);
        $this->assertSame('ZYXwvu98765', $item['youtube_video_id']);
        $this->assertSame('https://www.youtube.com/shorts/ZYXwvu98765', $item['youtube_url']);
    }

    public function testWithoutTheSocialApiKeyTheVideoIsBlockedNotPublished(): void
    {
        $this->setVideoEnv(['SOCIALAPI_API_KEY' => null]);
        $uuid = json_decode((string) $this->submit(VideoScriptValidatorTest::short())->getJSON(), true)['data']['item_uuid'];
        $repo = new VideoPipelineRepository();
        $item = $repo->findByUuid($uuid);
        $repo->update((int) $item['id'], ['step' => 'youtube_upload', 'render_url' => 'https://cdn.shotstack.example/render-abc.mp4', 'rendered_at' => gmdate('Y-m-d H:i:s'), 'render_plan_json' => ['duration' => 40]]);

        // In production the dev mock social publisher refuses: nothing can carry the video.
        \App\Libraries\Distribution\Providers\ProviderGuard::forceProduction(true);
        $result = (new \App\Libraries\Video\Automation\VideoPipelineRunner())->run((int) $item['id'], 'youtube_upload');
        \App\Libraries\Distribution\Providers\ProviderGuard::forceProduction(null);

        $this->assertSame('blocked', $result['status']);
        $item = $repo->findByUuid($uuid);
        $this->assertSame('blocked', $item['status']);
        $this->assertStringContainsString('YouTube via social-api.ai', (string) $item['last_error']);
        $this->assertNull($item['youtube_url']);
        $this->assertSame([], $this->http->to('#/v1/posts#'));
    }
}

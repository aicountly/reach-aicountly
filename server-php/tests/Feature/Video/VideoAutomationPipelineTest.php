<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Media\VideoMediaStore;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Render\MockRenderClient;
use App\Libraries\Video\Automation\Tts\Mp3Duration;
use App\Libraries\Video\Automation\Tts\MockTtsClient;
use App\Libraries\Video\Automation\VideoPipelineRepository;
use App\Libraries\Video\Automation\VideoPipelineRunner;
use App\Libraries\Video\Automation\VideoPipelineSweeper;
use App\Libraries\Video\Automation\VideoProviders;
use App\Libraries\Video\Automation\Visuals\MockVisualSource;
use App\Libraries\Video\Automation\YouTube\MockYouTubeClient;
use App\Models\SettingModel;
use Config\Services;
use Tests\Support\ApiTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;
use Tests\Unit\Video\Automation\VideoScriptValidatorTest;

/**
 * Routine script → Gemini vetting → System Bot approval → video queue jobs
 * (mock providers, testing only) → published with the YouTube id.
 *
 * @internal
 */
final class VideoAutomationPipelineTest extends ApiTestCase
{
    use VideoEnv;

    private const TOKEN = 'test-automation-token-0123456789abcdef';

    /** @var list<array> */
    private array $verdicts = [];

    private MockYouTubeClient $youtube;
    private MockRenderClient $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv([
            'REACH_AUTOMATION_TOKEN' => self::TOKEN,
            'AI_GEMINI_API_KEY'      => 'gemini-test-key',
            'AI_GEMINI_DEFAULT_MODEL' => 'gemini-test-flash',
            'MEDIA_SIGNING_KEY'      => 'media-signing-key-for-tests',
            'REACH_APP_URL'          => 'https://reach.example.test',
            'REACH_PUBLIC_BASE_URL'  => null,
            'VIDEO_MEDIA_STORAGE_PATH' => WRITEPATH . 'uploads/video-media-test/',
            'VIDEO_PIPELINE_MOCKS'   => null,
            'YOUTUBE_REFRESH_TOKEN'  => null,
            'YOUTUBE_CLIENT_ID'      => null,
            'YOUTUBE_CLIENT_SECRET'  => null,
            // These tests exercise the direct YouTube upload; the social-api.ai
            // path has its own test (VideoSocialApiPublishingTest).
            'VIDEO_YOUTUBE_PUBLISHER' => 'direct',
            'SOCIALAPI_API_KEY'      => null,
            'SOCIAL_PROVIDER'        => null,
        ]);
        $this->verdicts = [];
        $this->youtube  = new MockYouTubeClient();
        $this->renderer = new MockRenderClient();
        VideoProviders::override('vetting', new ContentVettingService(function (string $url, array $body): array {
            $verdict = array_shift($this->verdicts) ?? ['decision' => 'approve', 'reasons' => [], 'scores' => [], 'final_json' => '{}'];
            return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($verdict)]]]]]])];
        }));
        VideoProviders::override('tts', new MockTtsClient());
        VideoProviders::override('renderer', $this->renderer);
        VideoProviders::override('youtube', $this->youtube);
        VideoProviders::override('stills', new MockVisualSource());
        VideoProviders::override('screenshots', new MockVisualSource());
    }

    protected function tearDown(): void
    {
        $this->restoreVideoEnv();
        // The schema refresh rolls migrations back with data in place; older
        // down() methods re-add CHECK constraints that a video_script approval
        // row would violate.
        try {
            \Config\Database::connect()->table('reach_approvals')->where('subject_type', 'video_script')->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function submit(array $payload, ?string $token = self::TOKEN)
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['X-Automation-Token'] = $token;
        }
        return $this->withHeaders($headers)->withBodyFormat('json')->call('POST', 'v1/automation/video-scripts', $payload);
    }

    private function expect($res, int $status)
    {
        $res->assertStatus($status);
        return $res;
    }

    private static function data($res): array
    {
        return json_decode((string) $res->getJSON(), true)['data'] ?? [];
    }

    /** Run every video-queue job (delays collapsed), as the cron worker would. */
    private function drain(int $max = 80): array
    {
        $db   = \Config\Database::connect();
        $svc  = Services::jobService();
        $reg  = Services::jobHandlers();
        $seen = [];
        for ($i = 0; $i < $max; $i++) {
            $db->query("UPDATE reach_jobs SET available_at = NOW() - INTERVAL '1 second' WHERE status = 'pending' AND queue = 'video'");
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

    private function item(string $uuid): array
    {
        return (array) (new VideoPipelineRepository())->findByUuid($uuid);
    }

    // ── the happy path ─────────────────────────────────────────────────────

    public function testAVlogScriptIsVettedApprovedRenderedAndPublished(): void
    {
        $res = $this->submit(VideoScriptValidatorTest::vlog());
        $res->assertStatus(201);
        $data = self::data($res);
        $this->assertSame('approved', $data['status']);
        $this->assertSame('in_progress', $data['pipeline_status']);

        $db   = \Config\Database::connect();
        $item = $this->item($data['item_uuid']);
        $this->assertSame(SystemApprover::userId(), (int) $item['approved_by_user_id']);
        $approval = $db->table('reach_approvals')->where('id', (int) $item['approval_id'])->get()->getRowArray();
        $this->assertSame('video_script', $approval['subject_type']);
        $this->assertSame('approved', $approval['decision']);
        $this->assertSame(SystemApprover::userId(), (int) $approval['decided_by']);
        $meta = json_decode((string) $approval['metadata'], true);
        $run  = $db->table('reach_ai_vetting_runs')->where('id', (int) $meta['vetting_run_id'])->get()->getRowArray();
        $this->assertSame('video_script', $run['content_type']);
        $this->assertSame('approve', $run['decision']);

        $steps = $this->drain();
        $this->assertSame(['tts:advanced', 'visuals:advanced', 'render_submit:advanced', 'render_poll:advanced', 'youtube_upload:advanced', 'youtube_extras:published'], $steps);

        $item = $this->item($data['item_uuid']);
        $this->assertSame('published', $item['status']);
        $this->assertSame('done', $item['step']);
        $this->assertStringStartsWith('MOCK', (string) $item['youtube_video_id']);
        $this->assertStringStartsWith('mock://youtube/MOCK', (string) $item['youtube_url'], 'a mock upload never claims a youtube.com URL');
        $this->assertStringStartsWith('mock-', (string) $item['render_id']);
        $this->assertSame('set', $item['thumbnail_status']);
        $this->assertSame('set', $item['captions_status']);
        $this->assertNotNull($item['published_at']);
        $this->assertSame([], $item['social_post_ids_json'], 'vlogs are not offered to social');

        // The render got signed voice-over URLs and the branded template.
        $edit = $this->renderer->submitted[0];
        $this->assertSame(['width' => 1920, 'height' => 1080], $edit['output']['size']);
        $json = json_encode($edit);
        $this->assertStringContainsString('https:\/\/reach.example.test\/api\/v1\/public\/video-media\/', $json);
        $this->assertStringContainsString('ai-logo.png', $json);
        $this->assertStringStartsWith('https://reach.example.test/api/v1/public/video/shotstack/callback/' . $item['uuid'] . '?sig=', $edit['callback']);

        // YouTube got the metadata, the thumbnail and the caption track.
        $calls = array_column($this->youtube->calls, 0);
        $this->assertSame(['startUpload', 'continueUpload', 'setThumbnail', 'insertCaption'], $calls);
        $resource = $this->youtube->calls[0][1];
        $this->assertSame('GST returns explained in 4 minutes', $resource['snippet']['title']);
        $this->assertSame('public', $resource['status']['privacyStatus']);
        $this->assertStringContainsString("Chapters\n0:00 Intro", $resource['snippet']['description']);

        // Local voice-overs are purged once YouTube has the video; the thumbnail stays.
        $this->assertSame(1, $db->table('reach_video_media')->where('item_id', (int) $item['id'])->countAllResults());
    }

    public function testAShortIsAlsoOfferedToSocialThroughThePublisherSeam(): void
    {
        $data = self::data($this->expect($this->submit(VideoScriptValidatorTest::short()), 201));
        $steps = $this->drain();
        $this->assertContains('social:published', $steps);

        $item = $this->item($data['item_uuid']);
        $this->assertSame('published', $item['status']);
        $this->assertSame(['width' => 1080, 'height' => 1920], $this->renderer->submitted[0]['output']['size']);
        $this->assertStringEndsWith('#Shorts', $this->youtube->calls[0][1]['snippet']['title']);

        $posts = \Config\Database::connect()->table('reach_social_posts')->whereIn('id', $item['social_post_ids_json'])->get()->getResultArray();
        $this->assertSame(['instagram', 'facebook', 'linkedin'], array_column($posts, 'channel'));
        foreach ($posts as $post) {
            $this->assertSame('approved', $post['approval_status']);
            $this->assertSame(SystemApprover::userId(), (int) $post['approved_by']);
            $expected = ChannelProviderFactory::socialPublisherReadyFor($post['channel']) ? 'approved' : 'manual_queue';
            $this->assertSame($expected, $post['status']);
            $this->assertStringContainsString((string) $item['youtube_url'], $post['content']);
            $this->assertStringContainsString('youtube_short', (string) $post['media_refs']);
        }
    }

    public function testInProductionShortsWaitInTheManualSocialQueue(): void
    {
        $data = self::data($this->expect($this->submit(VideoScriptValidatorTest::short()), 201));
        $repo = new VideoPipelineRepository();
        $item = $this->item($data['item_uuid']);
        $repo->update((int) $item['id'], ['step' => 'social', 'youtube_video_id' => 'REALID12345', 'youtube_url' => 'https://www.youtube.com/shorts/REALID12345']);

        ProviderGuard::forceProduction(true);
        $result = (new VideoPipelineRunner())->run((int) $item['id'], 'social');
        ProviderGuard::forceProduction(null);

        $this->assertSame('published', $result['status']);
        $item  = $this->item($data['item_uuid']);
        $posts = \Config\Database::connect()->table('reach_social_posts')->whereIn('id', $item['social_post_ids_json'])->get()->getResultArray();
        $this->assertCount(3, $posts);
        $this->assertSame(['manual_queue'], array_values(array_unique(array_column($posts, 'status'))), 'no real social publisher: nothing claims to be posted');
        $this->assertSame(0, \Config\Database::connect()->table('reach_jobs')->where('job_type', 'distribution.dispatch.social')->countAllResults());
    }

    // ── vetting outcomes ───────────────────────────────────────────────────

    public function testARejectedScriptIsStoredWithReasonsAndNeverRendered(): void
    {
        $this->verdicts = [['decision' => 'reject', 'reasons' => ['Claims a payroll feature that does not exist.'], 'scores' => ['accuracy' => 2], 'final_json' => '{}']];
        $res = $this->submit(VideoScriptValidatorTest::short());
        $res->assertStatus(200);
        $data = self::data($res);
        $this->assertSame('rejected', $data['status']);
        $this->assertSame(['Claims a payroll feature that does not exist.'], $data['vetting_reasons']);
        $this->assertSame([], $this->drain());
        $this->assertSame(0, \Config\Database::connect()->table('reach_approvals')->where('subject_type', 'video_script')->countAllResults());
    }

    public function testARevisionIsReVettedOnceAndItsCorrectionsAreUsed(): void
    {
        $short = VideoScriptValidatorTest::short();
        $draft = ['title' => 'TDS is usually due on the 7th'];
        $this->verdicts = [
            ['decision' => 'revise', 'reasons' => ['Due dates can be extended; softened.'], 'scores' => [], 'final_json' => json_encode($draft)],
            ['decision' => 'approve', 'reasons' => [], 'scores' => [], 'final_json' => '{}'],
        ];
        $data = self::data($this->expect($this->submit($short), 201));
        $item = $this->item($data['item_uuid']);
        $this->assertSame('TDS is usually due on the 7th', $item['title']);
        $this->assertSame('TDS is usually due on the 7th', $item['script_json']['title']);
        $this->assertCount(2, $item['vetting_run_ids_json']);
        $this->assertContains('Due dates can be extended; softened.', $item['vetting_reasons_json']);
    }

    public function testStillNeedingChangesAfterOneRevisionIsNotApproved(): void
    {
        $this->verdicts = [
            ['decision' => 'revise', 'reasons' => ['a'], 'scores' => [], 'final_json' => json_encode(['title' => 'Better title here'])],
            ['decision' => 'revise', 'reasons' => ['b'], 'scores' => [], 'final_json' => json_encode(['title' => 'Even better title'])],
        ];
        $data = self::data($this->expect($this->submit(VideoScriptValidatorTest::short()), 200));
        $this->assertSame('rejected', $data['status']);
        $this->assertSame([], $this->drain());
    }

    public function testWhenVettingIsUnavailableTheScriptWaitsAndTheSweepVetsItLater(): void
    {
        $this->setVideoEnv(['AI_GEMINI_API_KEY' => null]);
        $res = $this->submit(VideoScriptValidatorTest::short());
        $res->assertStatus(202);
        $data = self::data($res);
        $this->assertSame('pending_vetting', $data['status']);
        $this->assertSame([], $this->drain(), 'unvetted content is never rendered');

        $this->setVideoEnv(['AI_GEMINI_API_KEY' => 'gemini-test-key']);
        \Config\Database::connect()->query("UPDATE reach_video_pipeline_items SET updated_at = NOW() - INTERVAL '1 hour'");
        $this->assertSame(1, (new VideoPipelineSweeper())->revetPending());
        $this->assertSame('in_progress', $this->item($data['item_uuid'])['status']);
    }

    // ── routine contract ───────────────────────────────────────────────────

    public function testTokenValidationIdempotencyAndQuota(): void
    {
        $this->submit(VideoScriptValidatorTest::short(), null)->assertStatus(401);
        $this->submit(VideoScriptValidatorTest::short(), 'wrong-token-wrong-token-wrong-token')->assertStatus(401);
        $this->submit(VideoScriptValidatorTest::short(['format' => 'reel']))->assertStatus(422);

        $this->submit(VideoScriptValidatorTest::short())->assertStatus(201);
        $again = $this->submit(VideoScriptValidatorTest::short());
        $again->assertStatus(200);
        $this->assertSame('already_received', self::data($again)['status']);

        // Default: 1 Short per day.
        $res = $this->submit(VideoScriptValidatorTest::short(['external_key' => 'short-2']));
        $res->assertStatus(429);
        $this->assertStringContainsString('quota', json_decode((string) $res->getJSON(), true)['error']);

        // The vlog quota is separate.
        $this->submit(VideoScriptValidatorTest::vlog())->assertStatus(201);

        ReachConfig::set('automation.video.enabled', false);
        $this->submit(VideoScriptValidatorTest::vlog(['external_key' => 'vlog-off']))->assertStatus(429);
    }

    public function testThePlanReportsQuotaChannelAndScreens(): void
    {
        $this->submit(VideoScriptValidatorTest::short())->assertStatus(201);
        $res = $this->withHeaders(['X-Automation-Token' => self::TOKEN])->call('GET', 'v1/automation/video-plan');
        $res->assertStatus(200);
        $plan = self::data($res);
        $this->assertSame(['limit' => 1, 'used' => 1, 'remaining' => 0], $plan['quota']['short']);
        $this->assertSame(1, $plan['quota']['vlog']['remaining']);
        $this->assertFalse($plan['youtube_connected']);
        $this->assertSame('TDS is due on the 7th', $plan['recent_titles'][0]['title']);
        $this->assertNotEmpty($plan['recommended_topics']);
        $this->assertContains('https://aicountly.com/smart-books', array_column($plan['screens'], 'url'));
    }

    // ── honest failure states ──────────────────────────────────────────────

    public function testWithoutAConnectedChannelTheVideoIsBlockedNotPublished(): void
    {
        $this->setVideoEnv(['YOUTUBE_CLIENT_ID' => 'cid', 'YOUTUBE_CLIENT_SECRET' => 'csecret', 'YOUTUBE_REFRESH_TOKEN' => null]);
        $http = (new FakeHttpTransport())->on('HEAD', '#^mock://#', new HttpResponse(200, '', ['content-length' => '1024']));
        VideoProviders::override('http', $http);
        VideoProviders::override('youtube', new \App\Libraries\Video\Automation\YouTube\YouTubeDataClient($http, new \App\Libraries\Video\Automation\YouTube\YouTubeOAuth($http)));

        $data = self::data($this->expect($this->submit(VideoScriptValidatorTest::short()), 201));
        $steps = $this->drain();
        $this->assertSame('youtube_upload:blocked', end($steps));

        $item = $this->item($data['item_uuid']);
        $this->assertSame('blocked', $item['status']);
        $this->assertSame('youtube_upload', $item['step']);
        $this->assertStringContainsString('Connect YouTube channel', (string) $item['last_error']);
        $this->assertNull($item['youtube_video_id']);
        $this->assertNull($item['published_at']);
    }

    public function testMockProvidersRefuseInProductionAndTheItemIsBlocked(): void
    {
        $data = self::data($this->expect($this->submit(VideoScriptValidatorTest::short()), 201));
        VideoProviders::reset();
        $this->setVideoEnv(['VIDEO_PIPELINE_MOCKS' => '1']);
        ProviderGuard::forceProduction(true);
        $item = $this->item($data['item_uuid']);
        $result = (new VideoPipelineRunner())->run((int) $item['id'], 'tts');
        ProviderGuard::forceProduction(null);

        $this->assertSame('blocked', $result['status']);
        $item = $this->item($data['item_uuid']);
        $this->assertSame('blocked', $item['status']);
        $this->assertStringContainsString('refused in production', (string) $item['last_error']);
    }

    public function testAFailedRenderFailsTheItemWithTheReason(): void
    {
        VideoProviders::override('renderer', new MockRenderClient('failed'));
        $data  = self::data($this->expect($this->submit(VideoScriptValidatorTest::short()), 201));
        $steps = $this->drain();
        $this->assertSame('render_poll:failed', end($steps));
        $item = $this->item($data['item_uuid']);
        $this->assertSame('failed', $item['status']);
        $this->assertStringContainsString('mock render failure', (string) $item['last_error']);

        // Staff can retry it from Reach.
        $h = $this->authAs('super_admin');
        VideoProviders::override('renderer', new MockRenderClient());
        $this->withHeaders($h)->call('POST', 'v1/video/pipeline/' . $data['item_uuid'] . '/retry')->assertStatus(200);
        $this->assertSame('in_progress', $this->item($data['item_uuid'])['status']);
    }

    // ── YouTube OAuth ──────────────────────────────────────────────────────

    public function testConnectingTheChannelStoresTheRefreshTokenEncryptedAndReleasesBlockedVideos(): void
    {
        $this->setVideoEnv(['YOUTUBE_CLIENT_ID' => 'client-123.apps.googleusercontent.com', 'YOUTUBE_CLIENT_SECRET' => 'gocspx-secret']);
        $repo = new VideoPipelineRepository();
        $data = self::data($this->expect($this->submit(VideoScriptValidatorTest::short()), 201));
        $item = $this->item($data['item_uuid']);
        $repo->update((int) $item['id'], ['status' => 'blocked', 'step' => 'youtube_upload', 'last_error' => 'No YouTube channel is connected']);
        \Config\Database::connect()->table('reach_jobs')->where('job_type', VideoPipelineRunner::JOB_TYPE)->delete();

        $http = (new FakeHttpTransport())
            ->on('POST', '#^https://oauth2\.googleapis\.com/token$#', FakeHttpTransport::json(200, [
                'access_token' => 'ya29.a', 'refresh_token' => '1//stored-refresh', 'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.force-ssl',
            ]))
            ->on('GET', '#youtube/v3/channels#', FakeHttpTransport::json(200, ['items' => [['id' => 'UCaicountly', 'snippet' => ['title' => 'Aicountly']]]]));
        VideoProviders::override('http', $http);
        VideoProviders::reset();
        VideoProviders::override('http', $http);

        $h   = $this->authAs('super_admin');
        $res = $this->withHeaders($h)->call('GET', 'v1/video/youtube/connect');
        $res->assertStatus(200);
        parse_str((string) parse_url(self::data($res)['consent_url'], PHP_URL_QUERY), $q);
        $this->assertSame('https://reach.example.test/api/v1/public/video/youtube/oauth-callback', $q['redirect_uri']);

        $cb = $this->call('GET', 'v1/public/video/youtube/oauth-callback', ['state' => $q['state'], 'code' => '4/0Ab-code']);
        $cb->assertStatus(302);
        $this->assertSame('https://reach.example.test/video?youtube=connected', $cb->response()->getHeaderLine('Location'));

        ReachConfig::flush();
        $this->assertSame('1//stored-refresh', ReachConfig::secret('video.youtube.refresh_token'));
        $raw = (new SettingModel())->where('key', 'video.youtube.refresh_token')->first();
        $this->assertStringNotContainsString('stored-refresh', (string) $raw['value_json'], 'encrypted at rest');

        $status = self::data($this->withHeaders($h)->call('GET', 'v1/video/youtube/status'));
        $this->assertTrue($status['connected']);
        $this->assertSame('Aicountly', $status['channel_title']);
        $this->assertStringNotContainsString('stored-refresh', json_encode($status));

        $this->assertSame('in_progress', $this->item($data['item_uuid'])['status'], 'blocked uploads are released');
        $this->assertSame(1, \Config\Database::connect()->table('reach_jobs')->where('job_type', VideoPipelineRunner::JOB_TYPE)->where('status', 'pending')->countAllResults());

        // The state is single-use.
        $replay = $this->call('GET', 'v1/public/video/youtube/oauth-callback', ['state' => $q['state'], 'code' => '4/0Ab-code']);
        $this->assertStringContainsString('reason=invalid_state', $replay->response()->getHeaderLine('Location'));

        // Disconnect forgets the token.
        $http->on('POST', '#oauth2\.googleapis\.com/revoke$#', new HttpResponse(200, '{}'));
        $this->withHeaders($h)->call('POST', 'v1/video/youtube/disconnect')->assertStatus(200);
        ReachConfig::flush();
        $this->assertNull(ReachConfig::secret('video.youtube.refresh_token'));
        $this->assertCount(1, $http->to('#/revoke$#'));
    }

    public function testConsentDeniedOrForgedStateIsRefused(): void
    {
        $res = $this->call('GET', 'v1/public/video/youtube/oauth-callback', ['state' => 'forged', 'code' => 'x']);
        $res->assertStatus(302);
        $this->assertStringContainsString('youtube=error&reason=invalid_state', $res->response()->getHeaderLine('Location'));
        ReachConfig::flush();
        $this->assertNull(ReachConfig::secret('video.youtube.refresh_token'));
    }

    // ── provider callbacks & media ─────────────────────────────────────────

    public function testShotstackCallbackNeedsTheSignatureAndOnlyBringsThePollForward(): void
    {
        $data = self::data($this->expect($this->submit(VideoScriptValidatorTest::short()), 201));
        $item = $this->item($data['item_uuid']);
        (new VideoPipelineRepository())->update((int) $item['id'], ['step' => 'render_poll', 'render_id' => 'render-abc-123']);
        $db = \Config\Database::connect();
        $db->table('reach_jobs')->where('job_type', VideoPipelineRunner::JOB_TYPE)->delete();
        VideoPipelineRunner::enqueue((int) $item['id'], 'render_poll', 3600);

        $this->withBodyFormat('json')->call('POST', 'v1/public/video/shotstack/callback/' . $item['uuid'] . '?sig=bad', ['id' => 'render-abc-123', 'status' => 'done'])->assertStatus(401);

        $sig = VideoPipelineRunner::callbackSignature((string) $item['uuid']);
        $this->withBodyFormat('json')->call('POST', 'v1/public/video/shotstack/callback/' . $item['uuid'] . '?sig=' . $sig, ['id' => 'render-abc-123', 'status' => 'done'])->assertStatus(200);
        $job = $db->query("SELECT available_at <= NOW() AS due FROM reach_jobs WHERE job_type = ? AND status = 'pending'", [VideoPipelineRunner::JOB_TYPE])->getRowArray();
        $this->assertTrue(in_array($job['due'], [true, 't', 1, '1'], true), 'the poll now runs immediately');
        $this->assertSame('render_poll', $this->item($data['item_uuid'])['step'], 'the callback body itself changes nothing');
    }

    public function testLegacyProviderCallbacksFailClosedWithoutAKey(): void
    {
        $this->setVideoEnv(['VIDEO_RENDER_HMAC_KEY' => null, 'YOUTUBE_WEBHOOK_SECRET' => null]);
        $this->withBodyFormat('json')->call('POST', 'v1/public/video/provider/render-callback', ['x' => 1])->assertStatus(503);
        $this->withBodyFormat('json')->call('POST', 'v1/public/video/provider/youtube-callback', ['x' => 1])->assertStatus(503);
    }

    public function testVoiceOversAreServedSignedWithRangeSupport(): void
    {
        $store = new VideoMediaStore();
        $mp3   = Mp3Duration::silentStream(1000);
        $media = $store->store($mp3, 'audio/mpeg', ['duration_ms' => 1000]);
        $url   = $store->signedUrl($media, 600);
        $path  = substr((string) parse_url($url, PHP_URL_PATH), strlen('/api/'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $full = $this->call('GET', $path, $q);
        $full->assertStatus(200);
        $this->assertSame('audio/mpeg', $full->response()->getHeaderLine('Content-Type'));
        $this->assertSame(strlen($mp3), strlen((string) $full->response()->getBody()));

        $part = $this->withHeaders(['Range' => 'bytes=0-99'])->call('GET', $path, $q);
        $part->assertStatus(206);
        $this->assertSame('bytes 0-99/' . strlen($mp3), $part->response()->getHeaderLine('Content-Range'));
        $this->assertSame(substr($mp3, 0, 100), (string) $part->response()->getBody());

        $this->call('GET', $path, ['exp' => $q['exp'], 'sig' => str_repeat('0', 64)])->assertStatus(404);
    }

    public function testStatusIsHonestAndTheListShowsVettingReasons(): void
    {
        $this->verdicts = [['decision' => 'reject', 'reasons' => ['Off-brand.'], 'scores' => [], 'final_json' => '{}']];
        $this->submit(VideoScriptValidatorTest::short())->assertStatus(200);

        $h      = $this->authAs('super_admin');
        $status = self::data($this->withHeaders($h)->call('GET', 'v1/video/pipeline/status'));
        $this->assertFalse($status['ready']);
        $this->assertContains('rendering', $status['missing']);
        $this->assertContains('youtube_channel', $status['missing']);
        $ops = self::data($this->withHeaders($h)->call('GET', 'v1/video/operations'));
        $this->assertSame('not_ready', $ops['status']);
        $this->assertSame('not connected', $ops['youtube_publisher']);

        $list = self::data($this->withHeaders($h)->call('GET', 'v1/video/pipeline'));
        $this->assertSame(1, $list['total']);
        $this->assertSame('rejected', $list['items'][0]['status']);
        $this->assertSame(['Off-brand.'], $list['items'][0]['vetting_reasons']);

        $show = self::data($this->withHeaders($h)->call('GET', 'v1/video/pipeline/' . $list['items'][0]['uuid']));
        $this->assertSame('short', $show['script']['format']);
        $this->assertSame([], $show['assets']);

        $this->withHeaders($this->authAs('viewer'))->call('GET', 'v1/video/pipeline')->assertStatus(403);
    }
}

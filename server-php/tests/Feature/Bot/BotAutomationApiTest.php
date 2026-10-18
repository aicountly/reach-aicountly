<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\Social\AutomationSocialPostService;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Bot\SocialChannelReadiness;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Render\MockRenderClient;
use App\Libraries\Video\Automation\Tts\MockTtsClient;
use App\Libraries\Video\Automation\VideoPipelineRepository;
use App\Libraries\Video\Automation\VideoProviders;
use App\Libraries\Video\Automation\Visuals\MockVisualSource;
use App\Libraries\Video\Automation\YouTube\MockYouTubeClient;
use Config\Database;
use Config\Services;
use Tests\Support\ApiTestCase;
use Tests\Support\Video\VideoEnv;
use Tests\Unit\Video\Automation\VideoScriptValidatorTest;

/**
 * The reach-command routine's side of an owner command: verify the command,
 * submit a reel (video pipeline) and social posts, offer a published video to
 * more networks, report back.
 *
 * @covers \App\Controllers\Api\V1\Automation\BotCommandAutomationController
 * @covers \App\Libraries\Automation\Social\AutomationSocialPostService
 * @covers \App\Libraries\Video\Automation\BotCommandVideoRules
 * @covers \App\Libraries\Video\Automation\VideoSocialDistributor
 *
 * @internal
 */
final class BotAutomationApiTest extends ApiTestCase
{
    use VideoEnv;

    private const TOKEN = 'test-automation-token-0123456789abcdef';

    /** @var list<array> */
    private array $verdicts = [];

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
            'VIDEO_YOUTUBE_PUBLISHER' => 'direct',
            'SOCIALAPI_API_KEY'       => null,
            'SOCIAL_PROVIDER'         => null,
        ]);
        $this->verdicts = [];
        $gemini = new ContentVettingService(function (string $url, array $body): array {
            $verdict = array_shift($this->verdicts) ?? ['decision' => 'approve', 'reasons' => [], 'scores' => [], 'final_json' => '{}'];
            return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($verdict)]]]]]])];
        });
        VideoProviders::override('vetting', $gemini);
        VideoProviders::override('tts', new MockTtsClient());
        VideoProviders::override('renderer', new MockRenderClient());
        VideoProviders::override('youtube', new MockYouTubeClient());
        VideoProviders::override('stills', new MockVisualSource());
        VideoProviders::override('screenshots', new MockVisualSource());
        AutomationSocialPostService::useVetting($gemini);
        SocialChannelReadiness::override(static fn (): array => array_map(
            static fn ($c) => ['channel' => $c, 'platform' => $c, 'label' => $c, 'ready' => true, 'account' => 'AICOUNTLY', 'reason' => null],
            ['youtube', 'instagram', 'facebook', 'linkedin', 'twitter'],
        ));
    }

    protected function tearDown(): void
    {
        AutomationSocialPostService::useVetting(null);
        SocialChannelReadiness::override(null);
        $this->restoreVideoEnv();
        try {
            Database::connect()->table('reach_approvals')->whereIn('subject_type', ['video_script', 'social'])->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function auto(string $method, string $path, array $body = [], ?string $token = self::TOKEN)
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['X-Automation-Token'] = $token;
        }
        return $this->withHeaders($headers)->withBodyFormat('json')->call($method, $path, $body);
    }

    private static function data($res): array
    {
        return json_decode((string) $res->getJSON(), true)['data'] ?? [];
    }

    /** An owner command the routine was fired with. */
    private function command(string $status = 'submitted'): array
    {
        $db = Database::connect();
        $db->table('reach_bot_commands')->insert([
            'instruction'      => 'Post a 30-second soft-launch reel announcing AICOUNTLY on all connected social platforms today',
            'channels_json'    => json_encode(['youtube', 'instagram', 'facebook', 'linkedin', 'twitter']),
            'idempotency_key'  => 'cmd-' . bin2hex(random_bytes(6)),
            'status'           => $status,
            'session_url'      => 'https://claude.ai/code/session_x',
            'created_by_name'  => 'Owner',
            'created_by_email' => 'owner@aicountly.com',
            'fired_at'         => gmdate('Y-m-d H:i:sP'),
        ]);
        return (array) $db->table('reach_bot_commands')->where('id', $db->insertID())->get()->getRowArray();
    }

    private function drainVideo(int $max = 80): void
    {
        $db  = Database::connect();
        $svc = Services::jobService();
        $reg = Services::jobHandlers();
        for ($i = 0; $i < $max; $i++) {
            $db->query("UPDATE reach_jobs SET available_at = NOW() - INTERVAL '1 second' WHERE status = 'pending' AND queue = 'video'");
            $row = $svc->reserve('video', 'phpunit');
            if (! $row) {
                break;
            }
            try {
                $svc->markCompleted((int) $row['id'], $reg->execute($row, 'phpunit'));
            } catch (\Throwable $e) {
                $svc->markFailed((int) $row['id'], $e->getMessage());
            }
        }
    }

    public function testVerifyAndReportUpdateTheCommand(): void
    {
        $cmd = $this->command();
        $this->auto('GET', 'v1/automation/commands/' . $cmd['uuid'], [], null)->assertStatus(401);
        $this->auto('GET', 'v1/automation/commands/00000000-0000-4000-8000-000000000000')->assertStatus(404);

        $got = self::data($this->auto('GET', 'v1/automation/commands/' . $cmd['uuid']));
        $this->assertSame($cmd['instruction'], $got['instruction']);
        $this->assertSame(['youtube', 'instagram', 'facebook', 'linkedin', 'twitter'], $got['channels']);

        $this->auto('POST', "v1/automation/commands/{$cmd['uuid']}/report", ['status' => 'done', 'summary' => 'x'])->assertStatus(422);
        $this->auto('POST', "v1/automation/commands/{$cmd['uuid']}/report", ['status' => 'completed', 'summary' => ''])->assertStatus(422);

        $res = $this->auto('POST', "v1/automation/commands/{$cmd['uuid']}/report", [
            'status'  => 'completed',
            'summary' => 'Submitted the soft-launch Short (item abc); Reach approved it and is rendering.',
            'links'   => [['label' => 'Video status', 'url' => 'https://reach.example.test/api/v1/automation/videos/abc'], 'javascript:alert(1)'],
        ]);
        $res->assertStatus(200);
        $row = Database::connect()->table('reach_bot_commands')->where('id', (int) $cmd['id'])->get()->getRowArray();
        $this->assertSame('completed', $row['status']);
        $this->assertSame('completed', $row['report_status']);
        $this->assertSame(1, (int) $row['report_count']);
        $this->assertEquals([['label' => 'Video status', 'url' => 'https://reach.example.test/api/v1/automation/videos/abc']], json_decode((string) $row['report_links_json'], true), 'only https links are kept');
        $this->assertSame(1, Database::connect()->table('reach_audit_logs')->where('action', 'bot.command.reported')->countAllResults());

        // A command whose fire failed never reached a session: nothing can report on it.
        $failed = $this->command('failed');
        $this->auto('POST', "v1/automation/commands/{$failed['uuid']}/report", ['status' => 'completed', 'summary' => 'x'])->assertStatus(409);
    }

    public function testTheSoftLaunchReelBypassesTheDailyQuotaAndGoesToEveryChosenNetwork(): void
    {
        ReachConfig::set('video.daily.shorts', 0); // the routine's Short for today is "used"
        ReachConfig::flush();
        $cmd = $this->command();

        $this->auto('POST', 'v1/automation/video-scripts', VideoScriptValidatorTest::short(['external_key' => 'short-2026-10-18-no-command']))->assertStatus(429);

        $res = $this->auto('POST', 'v1/automation/video-scripts', VideoScriptValidatorTest::short([
            'external_key'    => 'cmd-2026-10-18-soft-launch',
            'command_id'      => $cmd['uuid'],
            'social_channels' => ['instagram', 'facebook', 'linkedin', 'x', 'youtube'],
        ]));
        $res->assertStatus(201);
        $data = self::data($res);
        $this->assertSame('approved', $data['status']);
        $this->assertSame(['instagram', 'facebook', 'linkedin', 'twitter'], $data['social_channels']);
        $this->assertSame((int) $cmd['id'], $data['bot_command_id']);

        $this->drainVideo();
        $repo = new VideoPipelineRepository();
        $item = (array) $repo->findByUuid($data['item_uuid']);
        $this->assertSame('published', $item['status']);
        $posts = Database::connect()->table('reach_social_posts')->whereIn('id', $item['social_post_ids_json'])->orderBy('id')->get()->getResultArray();
        $this->assertSame(['instagram', 'facebook', 'linkedin', 'twitter'], array_column($posts, 'channel'));
        foreach ($posts as $p) {
            $this->assertSame((int) $cmd['id'], (int) $p['bot_command_id']);
            $this->assertSame(SystemApprover::userId(), (int) $p['approved_by']);
            $this->assertSame(ChannelProviderFactory::socialPublisherReadyFor($p['channel']) ? 'approved' : 'manual_queue', $p['status'], 'never claimed posted');
        }

        // The routine's daily quota was not touched by the owner's reel.
        $this->assertSame(0, $repo->countToday('short'));

        // Status for the routine's report.
        $view = self::data($this->auto('GET', 'v1/automation/videos/' . $data['item_uuid']));
        $this->assertSame('published', $view['pipeline_status']);
        $this->assertCount(4, $view['social_posts']);

        // Offering it again: networks that already have a post get no second one.
        $again = self::data($this->auto('POST', 'v1/automation/videos/' . $data['item_uuid'] . '/distribute', ['channels' => ['twitter', 'linkedin']]));
        $this->assertSame(['already_offered', 'already_offered'], array_column($again['results'], 'action'));
        $this->assertSame(4, Database::connect()->table('reach_social_posts')->where('bot_command_id', (int) $cmd['id'])->countAllResults());
        $this->auto('POST', 'v1/automation/videos/' . $data['item_uuid'] . '/distribute', ['channels' => ['myspace']])->assertStatus(422);

        // The command's history shows what Reach holds for it.
        $history = self::data($this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/bot/commands'))['commands'];
        $types   = array_count_values(array_column($history[0]['artifacts'], 'type'));
        $this->assertSame(['video' => 1, 'social_post' => 4], $types);

        // At most 3 scripts per command.
        foreach (['b', 'c'] as $suffix) {
            $this->auto('POST', 'v1/automation/video-scripts', VideoScriptValidatorTest::short(['external_key' => "cmd-2026-10-18-soft-launch-{$suffix}", 'command_id' => $cmd['uuid'], 'title' => "Another angle {$suffix}"]))->assertStatus(201);
        }
        $this->auto('POST', 'v1/automation/video-scripts', VideoScriptValidatorTest::short(['external_key' => 'cmd-2026-10-18-soft-launch-d', 'command_id' => $cmd['uuid'], 'title' => 'Fourth'] ))->assertStatus(429);
    }

    public function testVideoRulesRefuseUnknownOrClosedCommandsAndBadNetworks(): void
    {
        $this->auto('POST', 'v1/automation/video-scripts', VideoScriptValidatorTest::short(['external_key' => 'cmd-x-1', 'command_id' => '00000000-0000-4000-8000-000000000000']))->assertStatus(422);
        $closed = $this->command('completed');
        $this->auto('POST', 'v1/automation/video-scripts', VideoScriptValidatorTest::short(['external_key' => 'cmd-x-2', 'command_id' => $closed['uuid']]))->assertStatus(429);
        $this->auto('POST', 'v1/automation/video-scripts', VideoScriptValidatorTest::short(['external_key' => 'cmd-x-3', 'social_channels' => ['myspace']]))->assertStatus(422);
        $this->assertSame(0, Database::connect()->table('reach_video_pipeline_items')->countAllResults());
    }

    public function testAnUnpublishedVideoCannotBeDistributed(): void
    {
        $res  = $this->auto('POST', 'v1/automation/video-scripts', VideoScriptValidatorTest::short(['external_key' => 'short-2026-10-18-pending']));
        $uuid = self::data($res)['item_uuid'];
        $this->auto('POST', "v1/automation/videos/{$uuid}/distribute", [])->assertStatus(409);
        $this->auto('GET', 'v1/automation/videos/not-a-uuid')->assertStatus(404);
    }

    public function testASocialPostIsVettedApprovedByTheBotAndQueuedPerNetwork(): void
    {
        $cmd = $this->command();
        $payload = [
            'external_key' => 'cmd-2026-10-18-soft-launch-post',
            'command_id'   => $cmd['uuid'],
            'channels'     => ['linkedin', 'facebook', 'instagram'],
            'text'         => 'AICOUNTLY is now open: accounting, GST and compliance software for Indian businesses.',
            'link'         => 'https://aicountly.com',
            'hashtags'     => ['AICOUNTLY', '#GST'],
            'media_urls'   => ['https://aicountly.com/assests/images/launch.png'],
        ];
        $res = $this->auto('POST', 'v1/automation/social-posts', $payload);
        $res->assertStatus(201);
        $data = self::data($res);
        $this->assertSame('approved', $data['status']);
        $this->assertCount(3, $data['posts']);

        $db    = Database::connect();
        $posts = $db->table('reach_social_posts')->where('bot_command_id', (int) $cmd['id'])->orderBy('id')->get()->getResultArray();
        $this->assertSame(['linkedin', 'facebook', 'instagram'], array_column($posts, 'channel'));
        foreach ($posts as $p) {
            $this->assertStringContainsString('https://aicountly.com', $p['content']);
            $this->assertSame(['#AICOUNTLY', '#GST'], json_decode((string) $p['hashtags'], true));
            $this->assertSame([['url' => 'https://aicountly.com/assests/images/launch.png', 'type' => 'image']], json_decode((string) $p['media_refs'], true));
            $this->assertSame('approved', $p['approval_status']);
            $this->assertTrue(in_array($p['status'], ['approved', 'manual_queue'], true));
        }
        $this->assertSame(3, $db->table('reach_approvals')->where('subject_type', 'social')->where('decided_by', SystemApprover::userId())->countAllResults());
        $ready = count(array_filter($posts, static fn ($p) => $p['status'] === 'approved'));
        $this->assertSame($ready, $db->table('reach_jobs')->where('job_type', 'distribution.dispatch.social')->countAllResults());

        // Idempotent by external_key.
        $again = $this->auto('POST', 'v1/automation/social-posts', $payload);
        $again->assertStatus(200);
        $this->assertSame('already_received', self::data($again)['status']);
        $this->assertSame(3, $db->table('reach_social_posts')->where('bot_command_id', (int) $cmd['id'])->countAllResults());
        $this->assertSame('approved', self::data($this->auto('GET', 'v1/automation/social-posts/' . $data['uuid']))['status']);
    }

    public function testSocialPostRejectionsAndValidation(): void
    {
        $this->verdicts = [['decision' => 'reject', 'reasons' => ['Claims 10,000 customers; the context does not support it.'], 'scores' => [], 'final_json' => '{}']];
        $res = $this->auto('POST', 'v1/automation/social-posts', ['external_key' => 'social-reject-1', 'channels' => ['linkedin'], 'text' => 'Trusted by 10,000 customers!']);
        $res->assertStatus(200);
        $this->assertSame('rejected', self::data($res)['status']);
        $this->assertSame(0, Database::connect()->table('reach_social_posts')->countAllResults(), 'a rejected post is never created');

        $this->auto('POST', 'v1/automation/social-posts', ['external_key' => 'social-ig-1', 'channels' => ['instagram'], 'text' => 'Hello'])->assertStatus(422);
        $this->auto('POST', 'v1/automation/social-posts', ['external_key' => 'social-yt-1', 'channels' => ['youtube'], 'text' => 'Hello', 'media_urls' => ['https://aicountly.com/a.png']])->assertStatus(422);
        $this->auto('POST', 'v1/automation/social-posts', ['external_key' => 'social-x-1', 'channels' => ['x'], 'text' => str_repeat('a', 281)])->assertStatus(422);
        $this->auto('POST', 'v1/automation/social-posts', ['external_key' => 'social-m-1', 'channels' => ['linkedin'], 'text' => 'Hi', 'media_urls' => ['http://insecure.example/a.png']])->assertStatus(422);
        $this->auto('POST', 'v1/automation/social-posts', ['channels' => ['linkedin'], 'text' => 'Hi'])->assertStatus(422);

        ReachConfig::set('automation.social.daily_limit', 0);
        ReachConfig::flush();
        $this->auto('POST', 'v1/automation/social-posts', ['external_key' => 'social-quota-1', 'channels' => ['linkedin'], 'text' => 'Hi'])->assertStatus(429);
    }

    public function testSocialPostWaitsWhenVettingIsUnavailableAndRetriesOnResend(): void
    {
        AutomationSocialPostService::useVetting(new ContentVettingService(static fn (string $u, array $b): array => ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}']));
        $body = ['external_key' => 'social-pending-1', 'channels' => ['linkedin'], 'text' => 'AICOUNTLY is live.'];
        $res  = $this->auto('POST', 'v1/automation/social-posts', $body);
        $res->assertStatus(202);
        $this->assertSame('pending_vetting', self::data($res)['status']);
        $this->assertSame(0, Database::connect()->table('reach_social_posts')->countAllResults());

        AutomationSocialPostService::useVetting(new ContentVettingService(static fn (string $u, array $b): array => [
            'status' => 200,
            'body'   => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['decision' => 'approve', 'reasons' => [], 'scores' => [], 'final_json' => '{}'])]]]]]]),
        ]));
        $again = $this->auto('POST', 'v1/automation/social-posts', $body);
        $again->assertStatus(201);
        $this->assertSame(1, Database::connect()->table('reach_social_posts')->countAllResults());
    }
}

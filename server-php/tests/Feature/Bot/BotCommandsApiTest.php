<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Libraries\Bot\RoutineFireClient;
use App\Libraries\Bot\SocialChannelReadiness;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Http\HttpResponse;
use Config\Database;
use Tests\Support\ApiTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * Bot → Ask the bot: POST / GET v1/bot/commands fire the reach-command
 * routine through its API trigger (scripted HTTP here) and never twice.
 *
 * @covers \App\Libraries\Bot\BotCommandService
 * @covers \App\Libraries\Bot\BotCommandPayloadBuilder
 * @covers \App\Controllers\Api\V1\Bot\BotCommandController
 *
 * @internal
 */
final class BotCommandsApiTest extends ApiTestCase
{
    use VideoEnv;

    private const URL   = 'https://api.anthropic.com/v1/claude_code/routines/trig_01SoftLaunch/fire';
    private const TOKEN = 'sk-ant-oat01-test-routine-token';
    private const ASK   = 'Post a 30-second soft-launch reel announcing AICOUNTLY on all connected social platforms today';

    private FakeHttpTransport $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv(['BOT_COMMAND_ROUTINE_FIRE_URL' => null, 'BOT_COMMAND_ROUTINE_TOKEN' => null, 'REACH_APP_URL' => 'https://reach.example.test']);
        SocialChannelReadiness::override(static fn (): array => [
            ['channel' => 'youtube', 'platform' => 'youtube', 'label' => 'YouTube', 'ready' => true, 'account' => 'AICOUNTLY', 'reason' => null],
            ['channel' => 'instagram', 'platform' => 'instagram', 'label' => 'Instagram', 'ready' => true, 'account' => 'aicountly', 'reason' => null],
            ['channel' => 'linkedin', 'platform' => 'linkedin', 'label' => 'LinkedIn', 'ready' => false, 'account' => null, 'reason' => 'No active LinkedIn account is connected in social-api.ai.'],
        ]);
        $this->http = new FakeHttpTransport();
        RoutineFireClient::useTransport($this->http);
    }

    protected function tearDown(): void
    {
        RoutineFireClient::useTransport(null);
        SocialChannelReadiness::override(null);
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    private function configure(): void
    {
        ReachConfig::set('bot.command.routine_fire_url', self::URL);
        ReachConfig::set('bot.command.routine_token', self::TOKEN);
        ReachConfig::flush();
    }

    private function ask(array $headers, array $body)
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->call('POST', 'v1/bot/commands', $body);
    }

    private static function body($res): array
    {
        return json_decode((string) $res->getJSON(), true);
    }

    public function testNotConfiguredExplainsTheSetupAndSendsNothing(): void
    {
        $headers = $this->authAs('super_admin');
        $get     = self::body($this->withHeaders($headers)->call('GET', 'v1/bot/commands'))['data'];
        $this->assertFalse($get['configured']);
        $this->assertStringContainsString('reach-command', implode(' ', $get['setup']));
        $this->assertSame(['youtube', 'instagram', 'linkedin'], array_column($get['channels'], 'channel'));

        $res = $this->ask($headers, ['instruction' => self::ASK, 'idempotency_key' => 'cmd-test-not-configured']);
        $res->assertStatus(409);
        $this->assertSame('not_configured', self::body($res)['details']['reason']);
        $this->assertSame([], $this->http->requests);
        $this->assertSame(0, Database::connect()->table('reach_bot_commands')->countAllResults());
    }

    public function testTheFireUrlSettingOnlyAcceptsARoutineTrigger(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReachConfig::set('bot.command.routine_fire_url', 'https://evil.example.com/v1/claude_code/routines/trig_x/fire');
    }

    public function testA200StoresTheSessionAndAReplayNeverFiresAgain(): void
    {
        $this->configure();
        $this->http->on('POST', '#/routines/trig_01SoftLaunch/fire$#', FakeHttpTransport::json(200, [
            'type' => 'routine_fire', 'claude_code_session_id' => 'session_01XYZ', 'claude_code_session_url' => 'https://claude.ai/code/session_01XYZ',
        ]));
        $headers = $this->authAs('super_admin');

        $res = $this->ask($headers, ['instruction' => self::ASK, 'channels' => ['youtube', 'instagram', 'x'], 'attachments' => ['https://aicountly.com/assests/images/ai-logo.png'], 'idempotency_key' => 'cmd-test-soft-launch-1']);
        $res->assertStatus(201);
        $cmd = self::body($res)['data']['command'];
        $this->assertSame('submitted', $cmd['status']);
        $this->assertSame('https://claude.ai/code/session_01XYZ', $cmd['session_url']);
        $this->assertSame(['youtube', 'instagram', 'twitter'], $cmd['channels']);

        $this->assertCount(1, $this->http->requests);
        $sent = json_decode((string) $this->http->requests[0]['body'], true)['text'];
        $this->assertStringContainsString(self::ASK, $sent);
        $this->assertStringContainsString('command_id: ' . $cmd['uuid'], $sent);
        $this->assertStringContainsString('https://reach.example.test/api/v1/automation/commands/' . $cmd['uuid'] . '/report', $sent);
        $this->assertStringContainsString('Gemini vets', $sent);
        $this->assertStringContainsString('Never invent features', $sent);
        $this->assertStringContainsString('AI-assisted', $sent);
        $this->assertStringContainsString('"today_ist"', $sent);
        $this->assertStringContainsString('TARGET CHANNELS (chosen by the owner): youtube, instagram, twitter', $sent);
        $this->assertStringContainsString('https://aicountly.com/assests/images/ai-logo.png', $sent);
        $this->assertStringContainsString('No active LinkedIn account', $sent, 'channel readiness travels with the command');
        $this->assertLessThan(65536, mb_strlen($sent));
        $this->assertSame('Bearer ' . self::TOKEN, $this->http->requests[0]['headers']['Authorization']);

        // Double click / retried request with the same key: the same command, no second fire.
        $again = $this->ask($headers, ['instruction' => self::ASK, 'idempotency_key' => 'cmd-test-soft-launch-1']);
        $again->assertStatus(200);
        $this->assertTrue(self::body($again)['data']['replayed']);
        $this->assertSame($cmd['uuid'], self::body($again)['data']['command']['uuid']);
        $this->assertCount(1, $this->http->requests, 'never double-fire');
        $this->assertSame(1, Database::connect()->table('reach_bot_commands')->countAllResults());

        $owner = (int) Database::connect()->table('reach_users')->where('email', 'super_admin@test.aicountly.org')->get()->getRowArray()['id'];
        $audit = Database::connect()->table('reach_audit_logs')->where('action', 'bot.command.fired')->get()->getResultArray();
        $this->assertCount(1, $audit);
        $this->assertSame($owner, (int) $audit[0]['user_id']);
        $this->assertStringNotContainsString(self::TOKEN, (string) $audit[0]['new_value']);

        $history = self::body($this->withHeaders($headers)->call('GET', 'v1/bot/commands'))['data'];
        $this->assertTrue($history['configured']);
        $this->assertSame('https://claude.ai/code/session_01XYZ', $history['commands'][0]['session_url']);
        $this->assertSame(self::ASK, $history['commands'][0]['instruction']);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function failures(): iterable
    {
        yield '401 token refused' => [401, 502, 'failed'];
        yield '429 rate limited'  => [429, 502, 'failed'];
        yield '500 unknown'       => [500, 202, 'outcome_unknown'];
        yield '503 unavailable'   => [503, 502, 'failed'];
    }

    /** @dataProvider failures */
    public function testFailuresAreStoredHonestlyAndNotRetried(int $http, int $api, string $status): void
    {
        $this->configure();
        $this->http->on('POST', '#/fire$#', FakeHttpTransport::json($http, ['error' => ['message' => 'nope']], ['Retry-After' => '90']));

        $res = $this->ask($this->authAs('super_admin'), ['instruction' => self::ASK, 'idempotency_key' => 'cmd-test-fail-' . $http]);
        $res->assertStatus($api);
        $body = self::body($res);
        $cmd  = $api === 502 ? $body['details']['command'] : $body['data']['command'];
        if ($api === 502) {
            $this->assertFalse($body['ok']);
            $this->assertSame($cmd['fire_error'], $body['error']);
        }
        $this->assertSame($status, $cmd['status']);
        $this->assertSame($http, $cmd['fire_http_status']);
        $this->assertNull($cmd['session_url']);
        $this->assertNotEmpty($cmd['fire_error']);
        $this->assertCount(1, $this->http->requests, 'an answered fire is never re-sent');
        if ($http === 429) {
            $this->assertSame(90, $cmd['retry_after_secs']);
            $this->assertTrue($cmd['retryable']);
        }
        if ($http === 401) {
            $this->assertStringContainsString('token was refused', $cmd['fire_error']);
        }
    }

    public function testAConnectionThatNeverOpenedIsTriedOnceMore(): void
    {
        $this->configure();
        $this->http
            ->on('POST', '#/fire$#', new HttpResponse(0, '', [], 'Could not resolve host: api.anthropic.com'), 1)
            ->on('POST', '#/fire$#', FakeHttpTransport::json(200, ['type' => 'routine_fire', 'claude_code_session_url' => 'https://claude.ai/code/session_2']));
        $res = $this->ask($this->authAs('super_admin'), ['instruction' => self::ASK, 'idempotency_key' => 'cmd-test-dns-blip']);
        $res->assertStatus(201);
        $this->assertCount(2, $this->http->requests);
    }

    public function testValidationPermissionAndHourlyCap(): void
    {
        $this->configure();
        $this->http->on('POST', '#/fire$#', FakeHttpTransport::json(200, ['type' => 'routine_fire', 'claude_code_session_url' => 'https://claude.ai/code/session_3']));

        $this->ask($this->authAs('analyst'), ['instruction' => self::ASK])->assertStatus(403);

        $headers = $this->authAs('super_admin');
        $this->ask($headers, ['instruction' => 'x'])->assertStatus(422);
        $this->ask($headers, ['instruction' => self::ASK, 'channels' => ['myspace']])->assertStatus(422);
        $this->ask($headers, ['instruction' => self::ASK, 'attachments' => ['http://insecure.example.com/a.png']])->assertStatus(422);
        $this->ask($headers, ['instruction' => self::ASK, 'attachments' => ['file:///etc/passwd']])->assertStatus(422);
        $this->assertSame([], $this->http->requests);

        $db = Database::connect();
        for ($i = 0; $i < 25; $i++) {
            $db->table('reach_bot_commands')->insert(['instruction' => 'earlier', 'idempotency_key' => 'earlier-' . $i, 'status' => 'submitted', 'created_at' => gmdate('Y-m-d H:i:sP', time() - 600)]);
        }
        $cap = $this->ask($headers, ['instruction' => self::ASK, 'idempotency_key' => 'cmd-test-over-cap']);
        $cap->assertStatus(429);
        $this->assertNotSame('', $cap->response()->getHeaderLine('Retry-After'));
        $this->assertSame([], $this->http->requests, 'over the hourly cap nothing is fired');
    }

    public function testAStaleFiringRowIsSettledAsOutcomeUnknown(): void
    {
        $db = Database::connect();
        $db->table('reach_bot_commands')->insert(['instruction' => 'crashed mid-fire', 'idempotency_key' => 'stale-firing-1', 'status' => 'firing', 'created_at' => gmdate('Y-m-d H:i:sP', time() - 900)]);
        $rows = self::body($this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/bot/commands'))['data']['commands'];
        $this->assertSame('outcome_unknown', $rows[0]['status']);
        $this->assertStringContainsString('check claude.ai', $rows[0]['fire_error']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Commands\ReachIntegrationsCheck;
use App\Libraries\Settings\LiveIntegrationCheck;
use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\Tts\Mp3Duration;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * Every path of the live integration check (ok, auth failure, network
 * failure, render done / failed / timeout) against scripted HTTP. Nothing
 * here reaches the network: FakeHttpTransport answers 599 to anything
 * unscripted.
 *
 * @covers \App\Libraries\Settings\LiveIntegrationCheck
 * @covers \App\Libraries\Settings\ProviderConnectivityCheck
 * @covers \App\Commands\ReachIntegrationsCheck
 */
final class LiveIntegrationCheckTest extends CIUnitTestCase
{
    use VideoEnv;

    private const SECRETS = [
        'AI_GEMINI_API_KEY'     => 'gem-secret-key-123',
        'AI_OPENAI_API_KEY'     => 'sk-openai-secret-456',
        'DATAFORSEO_LOGIN'      => 'ops@aicountly.com',
        'DATAFORSEO_PASSWORD'   => 'dfs-secret-789',
        'SHOTSTACK_API_KEY'     => 'ss-secret-key-abc',
        'YOUTUBE_CLIENT_ID'     => 'client-123.apps.googleusercontent.com',
        'YOUTUBE_CLIENT_SECRET' => 'gocspx-secret-def',
        'YOUTUBE_REFRESH_TOKEN' => '1//refresh-secret-ghi',
        'SOCIALAPI_API_KEY'     => 'sapi_key_live_secret_xyz',
    ];

    private const RENDER_ID = 'd2b46ed6-998a-4d6b-9d91-b8cf0193a655';

    /** @var list<int> */
    private array $slept = [];
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv(self::SECRETS + [
            'SHOTSTACK_ENV'           => null,
            'SHOTSTACK_BASE_URL'      => null,
            'AI_GEMINI_BASE_URL'      => null,
            'AI_GEMINI_DEFAULT_MODEL' => null,
            'AI_OPENAI_BASE_URL'      => null,
            'AI_OPENAI_IMAGE_MODEL'   => null,
            'VIDEO_TTS_MODEL'         => null,
            'VIDEO_TTS_VOICE'         => null,
            'VIDEO_PIPELINE_MOCKS'    => null,
            'SOCIALAPI_BASE_URL'      => null,
            'SOCIAL_PROVIDER'         => null,
            'VIDEO_YOUTUBE_PUBLISHER' => 'direct', // the full-run tests exercise the direct uploader; see testAFullRunSkips…
        ]);
        \App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts::forget();
        $this->slept = [];
        $this->now   = 1_000_000;
    }

    protected function tearDown(): void
    {
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    private function check(HttpTransport $http): LiveIntegrationCheck
    {
        return new LiveIntegrationCheck(
            $http,
            function (int $secs): void {
                $this->slept[] = $secs;
                $this->now += $secs;
            },
            fn (): int => $this->now,
        );
    }

    /** Every provider answering the way a healthy account does. */
    private function healthy(): FakeHttpTransport
    {
        return (new FakeHttpTransport())
            ->on('GET', '#^https://generativelanguage\.googleapis\.com/v1beta/models\?#', FakeHttpTransport::json(200, ['models' => [
                ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'supportedGenerationMethods' => ['generateContent']],
            ]]))
            ->on('GET', '#^https://api\.openai\.com/v1/models$#', FakeHttpTransport::json(200, ['data' => [['id' => 'gpt-image-2.5-flare'], ['id' => 'gpt-4o-mini-tts']]]))
            ->on('GET', '#^https://api\.dataforseo\.com/v3/appendix/user_data$#', FakeHttpTransport::json(200, ['status_code' => 20000, 'tasks' => [['result' => [['money' => ['balance' => 42.5]]]]]]))
            ->on('GET', '#^https://api\.shotstack\.io/edit/stage/templates$#', FakeHttpTransport::json(200, ['success' => true, 'response' => ['templates' => [['id' => 't1']]]]))
            ->on('POST', '#^https://oauth2\.googleapis\.com/token$#', FakeHttpTransport::json(200, [
                'access_token' => 'ya29.access-secret-jkl', 'expires_in' => 3599, 'token_type' => 'Bearer',
                'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.force-ssl',
            ]))
            ->on('GET', '#^https://www\.googleapis\.com/youtube/v3/channels\?part=snippet,status&mine=true$#', FakeHttpTransport::json(200, ['items' => [[
                'id' => 'UC123', 'snippet' => ['title' => 'Aicountly'],
                'status' => ['privacyStatus' => 'public', 'longUploadsStatus' => 'allowed', 'madeForKids' => false],
            ]]]))
            ->on('POST', '#^https://api\.openai\.com/v1/audio/speech$#', new HttpResponse(200, Mp3Duration::silentStream(1000), ['content-type' => 'audio/mpeg']))
            ->on('GET', '#^https://api\.social-api\.ai/v1/accounts$#', FakeHttpTransport::json(200, [
                ['id' => 'acc_yt_1', 'name' => 'Aicountly', 'username' => 'aicountly', 'platform' => 'youtube', 'status' => 'active'],
                ['id' => 'acc_ig_1', 'name' => 'Aicountly', 'username' => 'aicountly.in', 'platform' => 'instagram', 'status' => 'active'],
                ['id' => 'acc_li_1', 'name' => 'Aicountly', 'platform' => 'linkedin', 'status' => 'expired'],
            ]))
            // Social inbox bot: the read-only probe of both inbox lists (shapes confirmed live).
            ->on('GET', '#^https://api\.social-api\.ai/v1/inbox/comments\?#', FakeHttpTransport::json(200, ['data' => [
                ['id' => 'cmt_1', 'type' => 'comment', 'platform' => 'instagram', 'text' => 'Does Aicountly file GSTR-1?', 'author' => ['username' => 'asha'], 'created_at' => '2026-10-18T09:00:00Z'],
            ], 'pagination' => ['has_more' => false]]))
            ->on('GET', '#^https://api\.social-api\.ai/v1/inbox/conversations\?#', FakeHttpTransport::json(200, ['data' => null, 'pagination' => ['has_more' => false, 'next_cursor' => ''], 'sync_state' => 'idle', 'last_synced_at' => null]));
    }

    /** @param array<string, mixed> $report */
    private function byTarget(array $report): array
    {
        return array_column($report['results'], null, 'target');
    }

    private function assertNoSecrets(mixed $report): void
    {
        $text = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . implode("\n", is_array($report) && isset($report['results']) ? ReachIntegrationsCheck::lines($report) : []);
        foreach (self::SECRETS + ['access' => 'ya29.access-secret-jkl', 'basic' => base64_encode('ops@aicountly.com:dfs-secret-789')] as $name => $secret) {
            if ($name === 'DATAFORSEO_LOGIN' || $name === 'YOUTUBE_CLIENT_ID') {
                continue; // identifiers, not secrets
            }
            $this->assertStringNotContainsString($secret, $text, "{$name} leaked into the report");
        }
    }

    // ── target parsing ──────────────────────────────────────────────────────

    public function testAFullRunSkipsTheDirectYouTubeLoginWhileVideosGoThroughSocialApi(): void
    {
        $this->setVideoEnv(['VIDEO_YOUTUBE_PUBLISHER' => 'social_api']);
        $http   = $this->healthy();
        $report = $this->check($http)->run();

        $yt = array_values(array_filter($report['results'], static fn ($r) => $r['target'] === 'youtube'))[0];
        $this->assertTrue($yt['skipped']);
        $this->assertTrue($yt['ok'], 'a skipped check does not fail the run');
        $this->assertStringContainsString('social-api.ai', $yt['message']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame(count($report['results']) - 1, $report['passed']);
        $this->assertSame([], $http->to('#oauth2\.googleapis\.com|youtube/v3#'), 'no Google call is made');

        $only = $this->check($this->healthy())->run(['youtube']);
        $this->assertArrayNotHasKey('skipped', $only['results'][0], '--only=youtube still tests the direct login');
    }

    public function testTargetsDefaultToAllAndKeepCanonicalOrder(): void
    {
        $this->assertSame(LiveIntegrationCheck::TARGETS, LiveIntegrationCheck::parseTargets(null));
        $this->assertSame(LiveIntegrationCheck::TARGETS, LiveIntegrationCheck::parseTargets('1'), 'a bare --only flag means all');
        $this->assertSame(['shotstack', 'tts'], LiveIntegrationCheck::parseTargets('tts, SHOTSTACK,tts'));
        $this->assertSame(['youtube'], LiveIntegrationCheck::parseTargets(['youtube']));
    }

    public function testAnUnknownTargetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown check "slack"');
        LiveIntegrationCheck::parseTargets('youtube,slack');
    }

    // ── all OK ──────────────────────────────────────────────────────────────

    public function testEveryCheckPassesAgainstHealthyProvidersWithoutLeakingSecrets(): void
    {
        $http   = $this->healthy();
        $report = $this->check($http)->run();

        $this->assertTrue($report['ok'], json_encode($report));
        $this->assertSame(7, $report['passed']);
        $this->assertSame(0, $report['failed']);
        $r = $this->byTarget($report);
        $this->assertSame(LiveIntegrationCheck::TARGETS, array_keys($r));

        $this->assertStringContainsString('Key accepted on the stage environment', $r['shotstack']['message']);
        $this->assertSame('stage', $r['shotstack']['detail']['environment']);
        $this->assertSame(1, $r['shotstack']['detail']['templates']);
        $this->assertSame('ss-secret-key-abc', $http->to('#shotstack#')[0]['headers']['x-api-key']);
        $this->assertSame([], $http->to('#/render#'), 'no render without --render-test');

        $this->assertStringContainsString('channel “Aicountly” (UC123)', $r['youtube']['message']);
        $this->assertSame('allowed', $r['youtube']['detail']['long_uploads']);
        $this->assertStringContainsString('likely allowed', $r['youtube']['detail']['custom_thumbnails']);
        $this->assertSame('public', $r['youtube']['detail']['upload_privacy']);
        $this->assertStringContainsString('locks every video uploaded through an unverified', $r['youtube']['detail']['upload_privacy_note']);
        $this->assertSame([], $http->to('#/upload/#'), 'the YouTube check never uploads');
        $this->assertSame('Bearer ya29.access-secret-jkl', $http->to('#youtube/v3/channels#')[0]['headers']['Authorization']);

        $this->assertStringContainsString('Balance: $42.5', $r['dataforseo']['message']);
        $this->assertStringContainsString('gemini-2.5-flash is available', $r['gemini']['message']);
        $this->assertStringContainsString('gpt-image-2.5-flare is available', $r['openai']['message']);

        $this->assertMatchesRegularExpression('/Synthesised [\d,]+ bytes of MP3 \(1\.0 s\) with gpt-4o-mini-tts \/ coral\./', $r['tts']['message']);
        $speech = json_decode((string) $http->to('#audio/speech#')[0]['body'], true);
        $this->assertSame(LiveIntegrationCheck::TTS_PHRASE, $speech['input']);

        $this->assertStringContainsString('Key accepted; 3 connected account(s): youtube 1, instagram 1, linkedin 1. Ready to post to: youtube, instagram. Nothing was posted.', $r['social_api']['message']);
        $this->assertSame('https://api.social-api.ai/v1', $r['social_api']['detail']['base_url']);
        $this->assertSame('youtube “Aicountly” @aicountly id=acc_yt_1 status=active', $r['social_api']['detail']['accounts'][0]);
        $this->assertStringContainsString('(not active)', $r['social_api']['detail']['accounts'][2]);
        $this->assertStringStartsWith('posts go to acc_yt_1 “Aicountly” (auto', $r['social_api']['detail']['targets']['youtube']);
        $this->assertStringContainsString('not available — No active LinkedIn account', $r['social_api']['detail']['targets']['linkedin']);
        $this->assertSame('Bearer sapi_key_live_secret_xyz', $http->to('#social-api#')[0]['headers']['Authorization']);
        $this->assertCount(3, $http->to('#social-api#'), 'GET /accounts + one GET of each inbox list; never a post');
        $this->assertSame([], array_filter($http->to('#social-api#'), static fn ($r) => $r['method'] !== 'GET'));
        $this->assertStringContainsString('Inbox OK: GET /inbox/comments OK: 1 item(s); GET /inbox/conversations OK: 0 item(s). Nothing was replied to.', $r['social_api']['message']);
        $this->assertSame('ok, 1 item(s)', $r['social_api']['detail']['inbox']['result /inbox/comments']);
        $this->assertSame('ok, 0 item(s)', $r['social_api']['detail']['inbox']['result /inbox/conversations']);
        $this->assertSame('youtube acc_yt_1', $r['social_api']['detail']['inbox']['account']);
        $this->assertStringContainsString('id, type, platform, text, author, created_at', $r['social_api']['detail']['inbox']['item fields /inbox/comments']);
        $this->assertStringNotContainsString('GSTR-1', json_encode($r['social_api']), 'the probe never prints message text');
        $inboxUrl = $http->to('#/v1/inbox#')[0]['url'];
        $this->assertStringContainsString('account_id=acc_yt_1', $inboxUrl);
        $this->assertStringContainsString('limit=5', $inboxUrl);

        $this->assertNoSecrets($report);
    }

    public function testTheInboxProbeNamesTheAlternativePathsThatAnswerWhenTheConfiguredOneIs404(): void
    {
        $http = (new FakeHttpTransport())
            ->on('GET', '#/v1/accounts$#', FakeHttpTransport::json(200, [['id' => 'acc_li', 'platform' => 'linkedin', 'status' => 'active']]))
            ->on('GET', '#/v1/inbox/conversations\?#', FakeHttpTransport::json(404, ['error' => ['code' => 'resource.not_found', 'message' => 'Not found']]))
            ->on('GET', '#/v1/inbox/comments\?#', FakeHttpTransport::json(200, ['items' => [['comment_id' => 'c1', 'message' => 'hi']]]))
            ->on('GET', '#/v1/inbox/messages\?#', FakeHttpTransport::json(200, ['data' => [['id' => 'm1', 'text' => 'hi']]]))
            ->on('GET', '#/v1/conversations\?#', FakeHttpTransport::json(200, ['weird' => true]))
            ->on('GET', '#/v1/(inbox|comments|messages)\?#', FakeHttpTransport::json(404, []));
        $r = $this->check($http)->run(['social_api'])['results'][0];

        $this->assertFalse($r['ok'], 'posting works, but the inbox bot cannot read — the check says so');
        $this->assertStringContainsString('Key accepted; 1 connected account(s)', $r['message']);
        $this->assertStringContainsString('Inbox FAILED: GET /inbox/comments OK: 1 item(s); GET /inbox/conversations FAILED (HTTP 404); These answered: /inbox/messages (1 item(s)), /conversations (answered, shape not recognised) — set social.inbox.list_path (comma-separated).', $r['message']);
        $this->assertSame('{weird: bool}', $r['detail']['inbox']['shape /conversations'], 'an unreadable answer is shown as types only');
        $this->assertSame([], array_filter($http->requests, static fn ($q) => $q['method'] !== 'GET'), 'the probe only ever GETs');
    }

    public function testTheInboxProbeFailsOnAnUnrecognisedShapeAndIsSkippedWhenTheBotIsOff(): void
    {
        $http = (new FakeHttpTransport())
            ->on('GET', '#/v1/accounts$#', FakeHttpTransport::json(200, [['id' => 'acc_li', 'platform' => 'linkedin', 'status' => 'active']]))
            ->on('GET', '#/v1/inbox/(comments|conversations)\?#', FakeHttpTransport::json(200, ['total' => 3, 'unexpected' => ['a' => 1]]));
        $r = $this->check($http)->run(['social_api'])['results'][0];
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('GET /inbox/comments answered in a shape Reach does not read', $r['message']);
        $this->assertSame('{total: int, unexpected: {a: int}}', $r['detail']['inbox']['shape /inbox/comments']);

        $this->setVideoEnv(['SOCIAL_INBOX_ENABLED' => 'false']);
        $http2 = (new FakeHttpTransport())->on('GET', '#/v1/accounts$#', FakeHttpTransport::json(200, [['id' => 'acc_li', 'platform' => 'linkedin', 'status' => 'active']]));
        $r2 = $this->check($http2)->run(['social_api'])['results'][0];
        $this->assertTrue($r2['ok']);
        $this->assertStringContainsString('not checked', $r2['detail']['inbox']);
        $this->assertCount(1, $http2->requests);
        $this->setVideoEnv(['SOCIAL_INBOX_ENABLED' => null]);
    }

    public function testOnlyTheRequestedChecksRun(): void
    {
        $http   = $this->healthy();
        $report = $this->check($http)->run(['dataforseo']);
        $this->assertSame(['dataforseo'], array_column($report['results'], 'target'));
        $this->assertCount(1, $http->requests);
    }

    // ── not configured ──────────────────────────────────────────────────────

    public function testMissingCredentialsFailWithoutCallingAnyone(): void
    {
        $this->setVideoEnv(array_fill_keys(array_keys(self::SECRETS), null));
        $http   = new FakeHttpTransport();
        $report = $this->check($http)->run();

        $this->assertFalse($report['ok']);
        $this->assertSame(7, $report['failed']);
        $this->assertSame([], $http->requests);
        $r = $this->byTarget($report);
        $this->assertStringContainsString('Shotstack API key is not set', $r['shotstack']['message']);
        $this->assertStringContainsString('OAuth client is not configured', $r['youtube']['message']);
        $this->assertStringContainsString('OpenAI API key is not set', $r['tts']['message']);
        $this->assertStringContainsString('No credential saved', $r['gemini']['message']);
        $this->assertStringContainsString('social-api.ai API key is not set', $r['social_api']['message']);
    }

    public function testYouTubeWithoutARefreshTokenSaysToConnect(): void
    {
        $this->setVideoEnv(['YOUTUBE_REFRESH_TOKEN' => null]);
        $report = $this->check(new FakeHttpTransport())->run(['youtube']);
        $this->assertStringContainsString('No YouTube channel is connected', $report['results'][0]['message']);
    }

    // ── auth failures ───────────────────────────────────────────────────────

    public function testRejectedCredentialsFailEveryCheckWithoutLeakingSecrets(): void
    {
        $http = (new FakeHttpTransport())
            ->on('GET', '#generativelanguage#', FakeHttpTransport::json(400, ['error' => ['message' => 'API key not valid. key=gem-secret-key-123']]))
            ->on('GET', '#api\.openai\.com/v1/models#', FakeHttpTransport::json(401, ['error' => ['message' => 'Incorrect API key provided: sk-openai-secret-456']]))
            ->on('GET', '#dataforseo#', FakeHttpTransport::json(401, ['status_code' => 40100, 'status_message' => 'You are not authorized.']))
            ->on('GET', '#shotstack\.io/edit/stage/templates#', FakeHttpTransport::json(403, ['message' => 'Forbidden']))
            ->on('POST', '#oauth2\.googleapis\.com/token#', FakeHttpTransport::json(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']))
            ->on('POST', '#audio/speech#', FakeHttpTransport::json(401, ['error' => ['message' => 'Incorrect API key provided: sk-openai-secret-456']]))
            ->on('GET', '#social-api\.ai/v1/accounts#', FakeHttpTransport::json(401, ['error' => ['code' => 'auth.invalid_key', 'message' => 'Invalid API key sapi_key_live_secret_xyz']]));

        $report = $this->check($http)->run();
        $this->assertFalse($report['ok']);
        $this->assertSame(7, $report['failed']);
        $r = $this->byTarget($report);

        $this->assertStringContainsString('Shotstack rejected the key for the stage environment (HTTP 403)', $r['shotstack']['message']);
        $this->assertStringContainsString('revoked or expired (invalid_grant)', $r['youtube']['message']);
        $this->assertSame('auth', $r['youtube']['detail']['kind']);
        $this->assertStringContainsString('DataForSEO rejected the credentials (HTTP 401, status 40100). You are not authorized.', $r['dataforseo']['message']);
        $this->assertStringContainsString('Gemini rejected the key (HTTP 400)', $r['gemini']['message']);
        $this->assertStringContainsString('OpenAI rejected the key (HTTP 401)', $r['openai']['message']);
        $this->assertStringContainsString('OpenAI TTS speech failed (HTTP 401)', $r['tts']['message']);
        $this->assertSame('auth', $r['tts']['detail']['kind']);
        $this->assertStringContainsString('social-api.ai rejected the API key (HTTP 401)', $r['social_api']['message']);
        $this->assertSame('auth', $r['social_api']['detail']['kind']);

        $this->assertNoSecrets($report);
    }

    public function testAYouTubeChannelReadRefusalFails(): void
    {
        $http = (new FakeHttpTransport())
            ->on('POST', '#oauth2\.googleapis\.com/token#', FakeHttpTransport::json(200, ['access_token' => 'ya29.access-secret-jkl', 'expires_in' => 3599]))
            ->on('GET', '#youtube/v3/channels#', FakeHttpTransport::json(403, ['error' => ['message' => 'Request had insufficient authentication scopes.', 'errors' => [['reason' => 'insufficientPermissions']]]]));
        $r = $this->check($http)->run(['youtube'])['results'][0];
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('YouTube channels.list failed (HTTP 403): Request had insufficient authentication scopes.', $r['message']);
        $this->assertNoSecrets(['r' => $r]);
    }

    public function testATokenWithoutTheUploadScopeFails(): void
    {
        $http = (new FakeHttpTransport())
            ->on('POST', '#oauth2\.googleapis\.com/token#', FakeHttpTransport::json(200, ['access_token' => 'ya29.access-secret-jkl', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/youtube.force-ssl']))
            ->on('GET', '#youtube/v3/channels#', FakeHttpTransport::json(200, ['items' => [['id' => 'UC123', 'snippet' => ['title' => 'Aicountly'], 'status' => ['longUploadsStatus' => 'eligible']]]]));
        $r = $this->check($http)->run(['youtube'])['results'][0];
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('did not grant https://www.googleapis.com/auth/youtube.upload', $r['message']);
        $this->assertStringContainsString('phone-verified', $r['detail']['custom_thumbnails']);
    }

    public function testASecretReflectedInAnErrorBodyIsScrubbed(): void
    {
        $http = (new FakeHttpTransport())->on('GET', '#shotstack#', FakeHttpTransport::json(500, ['message' => 'internal error for key ss-secret-key-abc']));
        $r    = $this->check($http)->run(['shotstack'])['results'][0];
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('HTTP 500', $r['message']);
        $this->assertStringContainsString('[REDACTED]', $r['message']);
        $this->assertNoSecrets(['r' => $r]);
    }

    // ── network failures ────────────────────────────────────────────────────

    public function testNetworkFailuresAreReportedAsSuch(): void
    {
        $down = new HttpResponse(0, '', [], 'Could not resolve host');
        $http = (new FakeHttpTransport())
            ->on('GET', '#.#', $down)
            ->on('POST', '#.#', $down);

        $report = $this->check($http)->run();
        $this->assertFalse($report['ok']);
        $this->assertSame(7, $report['failed']);
        $r = $this->byTarget($report);
        $this->assertStringContainsString('Could not reach social-api.ai at https://api.social-api.ai/v1 (network error)', $r['social_api']['message']);
        $this->assertCount(1, $http->to('#social-api#'), 'the live check does not retry');
        foreach (['gemini' => 'Gemini', 'openai' => 'OpenAI', 'dataforseo' => 'DataForSEO', 'shotstack' => 'Shotstack'] as $t => $name) {
            $this->assertSame("Could not reach {$name} (network error: Could not resolve host).", $r[$t]['message'], $t);
        }
        $this->assertStringContainsString('Google OAuth token refresh failed: Could not resolve host', $r['youtube']['message']);
        $this->assertStringContainsString('OpenAI TTS speech failed: Could not resolve host', $r['tts']['message']);
        $this->assertSame('transient', $r['tts']['detail']['kind']);
        $this->assertNoSecrets($report);
    }

    public function testATimeoutCapLimitsEveryCall(): void
    {
        $seen = [];
        $http = new class ($seen) implements HttpTransport {
            public function __construct(private array &$seen)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
            {
                $this->seen[] = $options['timeout'] ?? null;
                return new HttpResponse(0, '', [], 'timed out');
            }
        };
        (new LiveIntegrationCheck($http, timeoutCap: 7))->run(['tts', 'openai']);
        $this->assertSame([7, 7], $seen, 'TTS asks for 120 s and the model list for 20 s; both are capped');
    }

    // ── Shotstack test render ───────────────────────────────────────────────

    private function withRender(FakeHttpTransport $http, callable|HttpResponse $status, string $env = 'stage'): FakeHttpTransport
    {
        return $http
            ->on('GET', '#^https://api\.shotstack\.io/edit/' . $env . '/templates$#', FakeHttpTransport::json(200, ['response' => ['templates' => []]]))
            ->on('POST', '#^https://api\.shotstack\.io/edit/' . $env . '/render$#', FakeHttpTransport::json(201, ['response' => ['id' => self::RENDER_ID]]))
            ->on('GET', '#^https://api\.shotstack\.io/edit/' . $env . '/render/' . self::RENDER_ID . '\?data=false$#', $status);
    }

    public function testRenderTestSubmitsATitleCardAndPollsUntilDone(): void
    {
        $polls = 0;
        $http  = $this->withRender(new FakeHttpTransport(), function () use (&$polls) {
            $polls++;
            return $polls < 3
                ? FakeHttpTransport::json(200, ['response' => ['status' => $polls === 1 ? 'queued' : 'rendering']])
                : FakeHttpTransport::json(200, ['response' => ['status' => 'done', 'url' => 'https://cdn.shotstack.io/au/stage/x/' . self::RENDER_ID . '.mp4']]);
        });
        $lines  = [];
        $report = $this->check($http)->run(['shotstack'], true, function (string $t, string $line) use (&$lines) {
            $lines[] = $line;
        });

        $r = $report['results'][0];
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertStringContainsString('Test render done in 15 s: https://cdn.shotstack.io/au/stage/x/' . self::RENDER_ID . '.mp4', $r['message']);
        $this->assertSame(self::RENDER_ID, $r['detail']['render']['render_id']);
        $this->assertSame([5, 5, 5], $this->slept);
        $this->assertStringContainsString('submitted on stage', $lines[0]);

        $edit = json_decode((string) $http->to('#/render$#', 'POST')[0]['body'], true);
        $this->assertSame(3, $edit['timeline']['tracks'][0]['clips'][0]['length']);
        $this->assertSame('text', $edit['timeline']['tracks'][0]['clips'][0]['asset']['type']);
        $this->assertCount(1, $edit['timeline']['tracks'], 'title card only');
        $this->assertSame('ss-secret-key-abc', $http->to('#/render$#', 'POST')[0]['headers']['x-api-key']);
        $this->assertNoSecrets($report);
    }

    public function testRenderTestUsesTheProductionEnvironmentWhenConfigured(): void
    {
        $this->setVideoEnv(['SHOTSTACK_ENV' => 'v1']);
        $http = $this->withRender(new FakeHttpTransport(), FakeHttpTransport::json(200, ['response' => ['status' => 'done', 'url' => 'https://cdn.shotstack.io/au/v1/x/r.mp4']]), 'v1');
        $r    = $this->check($http)->run(['shotstack'], true)['results'][0];
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertStringContainsString('v1 environment (production, billed)', $r['message']);
        $this->assertSame('production — renders are billed', $r['detail']['billing']);
        $this->assertCount(1, $http->to('#/edit/v1/render$#', 'POST'));
    }

    public function testRenderTestReportsAFailedRender(): void
    {
        $http = $this->withRender(new FakeHttpTransport(), FakeHttpTransport::json(200, ['response' => ['status' => 'failed', 'error' => 'Font not found']]));
        $r    = $this->check($http)->run(['shotstack'], true)['results'][0];
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Test render failed: Font not found.', $r['message']);
    }

    public function testRenderTestTimesOut(): void
    {
        $http = $this->withRender(new FakeHttpTransport(), FakeHttpTransport::json(200, ['response' => ['status' => 'rendering']]));
        $r    = $this->check($http)->run(['shotstack'], true)['results'][0];

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Test render timed out after 180 s (last status: rendering) — render id ' . self::RENDER_ID, $r['message']);
        $this->assertSame(LiveIntegrationCheck::RENDER_TIMEOUT_SECS / LiveIntegrationCheck::RENDER_POLL_SECS, count($this->slept));
    }

    public function testATransientPollingErrorIsRetried(): void
    {
        $polls = 0;
        $http  = $this->withRender(new FakeHttpTransport(), function () use (&$polls) {
            $polls++;
            return $polls === 1 ? new HttpResponse(502, 'Bad gateway') : FakeHttpTransport::json(200, ['response' => ['status' => 'done', 'url' => 'https://cdn.shotstack.io/r.mp4']]);
        });
        $r = $this->check($http)->run(['shotstack'], true)['results'][0];
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(2, $polls);
    }

    public function testARejectedRenderSubmitFails(): void
    {
        $http = (new FakeHttpTransport())
            ->on('GET', '#/templates$#', FakeHttpTransport::json(200, ['response' => ['templates' => []]]))
            ->on('POST', '#/render$#', FakeHttpTransport::json(400, ['response' => ['error' => 'Invalid timeline']]));
        $r = $this->check($http)->run(['shotstack'], true)['results'][0];
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Shotstack render submit failed (HTTP 400): Invalid timeline', $r['message']);
        $this->assertSame([], $this->slept);
    }

    // ── command output ──────────────────────────────────────────────────────

    public function testTheCommandPrintsOneOkOrFailLinePerCheck(): void
    {
        $http = (new FakeHttpTransport())->on('GET', '#dataforseo#', FakeHttpTransport::json(401, ['status_code' => 40100]));
        $report = $this->check($this->mergeHealthyExcept($http))->run(['youtube', 'dataforseo']);

        $lines = ReachIntegrationsCheck::lines($report);
        $this->assertMatchesRegularExpression('/^  OK    youtube     Token refresh OK; channel/', $lines[0]);
        $this->assertContains('        long_uploads: allowed', $lines);
        $fail = array_values(array_filter($lines, static fn ($l) => str_starts_with($l, '  FAIL')));
        $this->assertCount(1, $fail);
        $this->assertStringStartsWith('  FAIL  dataforseo  DataForSEO rejected the credentials (HTTP 401', $fail[0]);
        $this->assertSame('1 passed, 1 failed.', end($lines));
        $this->assertNoSecrets($report);
    }

    /** $first answers what it scripts; everything else falls through to healthy(). */
    private function mergeHealthyExcept(FakeHttpTransport $first): HttpTransport
    {
        $healthy = $this->healthy();
        return new class ($first, $healthy) implements HttpTransport {
            public function __construct(private readonly FakeHttpTransport $first, private readonly FakeHttpTransport $fallback)
            {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
            {
                $res = $this->first->request($method, $url, $headers, $body, $options);
                return $res->status === 599 ? $this->fallback->request($method, $url, $headers, $body, $options) : $res;
            }
        };
    }
}

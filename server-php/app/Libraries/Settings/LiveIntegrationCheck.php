<?php

declare(strict_types=1);

namespace App\Libraries\Settings;

use App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiPlatforms;
use App\Libraries\Video\Automation\Http\CurlTransport;
use App\Libraries\Video\Automation\YouTube\YouTubePublisherMode;
use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\Render\ShotstackClient;
use App\Libraries\Video\Automation\Render\TimelineBuilder;
use App\Libraries\Video\Automation\Tts\Mp3Duration;
use App\Libraries\Video\Automation\Tts\OpenAiTtsClient;
use App\Libraries\Video\Automation\VideoProviderException;
use App\Libraries\Video\Automation\YouTube\YouTubeDataClient;
use App\Libraries\Video\Automation\YouTube\YouTubeOAuth;
use InvalidArgumentException;
use Throwable;

/**
 * Live checks of the third-party services the automation pipelines depend on,
 * run with the credentials saved in Settings → AI & Automation (or the .env
 * fallbacks): `php spark reach:integrations-check` and the "Run live checks"
 * button (POST v1/settings/automation/live-check).
 *
 * Each check makes the cheapest authenticated call that proves the
 * credential works:
 *   gemini / openai / dataforseo → ProviderConnectivityCheck (model list / account read)
 *   shotstack  → GET /edit/{env}/templates; optionally (CLI only) a 3-second
 *                title-card render on the configured environment, polled to done
 *   youtube    → OAuth token refresh + channels.list(snippet,status, mine=true);
 *                never uploads
 *   tts        → one short phrase through OpenAI TTS (bytes discarded)
 *   social_api → GET /v1/accounts on social-api.ai with the saved key: lists the
 *                connected accounts (platform, name, id — never the key) and
 *                which account each platform would post to; never posts. With
 *                the social inbox bot on, also GET the inbox list once
 *                (social.inbox.list_path) to confirm its shape; on a
 *                404 the usual alternative paths are tried, GET only
 *
 * Results never carry a credential: every message and detail is scrubbed of
 * the configured secret values before it is returned.
 *
 * Always uses the real providers, even when VIDEO_PIPELINE_MOCKS is set —
 * the point is to talk to the live services.
 */
final class LiveIntegrationCheck
{
    public const TARGETS = ['shotstack', 'youtube', 'dataforseo', 'gemini', 'openai', 'tts', 'social_api'];

    public const RENDER_TIMEOUT_SECS = 180;
    public const RENDER_POLL_SECS    = 5;
    public const RENDER_LENGTH_SECS  = 3;

    /** About one second of speech. */
    public const TTS_PHRASE = 'Reach live check.';

    private HttpTransport $http;
    private ProviderConnectivityCheck $providers;
    /** @var callable(int): void */
    private $sleep;
    /** @var callable(): int */
    private $clock;

    /** @var list<string> values scrubbed from every result */
    private array $extraSecrets = [];

    /**
     * @param int|null $timeoutCap seconds; caps every HTTP call (the web endpoint
     *                             uses this so one slow provider cannot hold the request)
     */
    public function __construct(?HttpTransport $http = null, ?callable $sleep = null, ?callable $clock = null, ?int $timeoutCap = null)
    {
        $http ??= new CurlTransport();
        if ($timeoutCap !== null) {
            $http = new class ($http, $timeoutCap) implements HttpTransport {
                public function __construct(private readonly HttpTransport $inner, private readonly int $cap)
                {
                }

                public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
                {
                    $options['timeout'] = min((int) ($options['timeout'] ?? $this->cap), $this->cap);
                    return $this->inner->request($method, $url, $headers, $body, $options);
                }
            };
        }
        $this->http  = $http;
        $this->sleep = $sleep ?? static function (int $secs): void {
            sleep($secs);
        };
        $this->clock = $clock ?? static fn (): int => time();

        // ProviderConnectivityCheck speaks "Name: value" header lines; route it
        // through the same transport so one fake drives every check in tests.
        $this->providers = new ProviderConnectivityCheck(function (string $method, string $url, array $lines): array {
            $headers = ['Accept' => 'application/json'];
            foreach ($lines as $line) {
                $parts = explode(':', (string) $line, 2);
                if (count($parts) === 2) {
                    $headers[trim($parts[0])] = trim($parts[1]);
                }
            }
            $res = $this->http->request($method, $url, $headers, null, ['timeout' => 20]);
            return ['status' => $res->status, 'body' => $res->body, 'error' => $res->transportError];
        });
    }

    /**
     * Turn "--only=a,b" / ['a','b'] into a validated list; empty = every target.
     *
     * @param string|list<string>|null $only
     * @return list<string>
     * @throws InvalidArgumentException on an unknown target
     */
    public static function parseTargets(string|array|null $only): array
    {
        $raw = is_array($only) ? $only : explode(',', (string) $only);
        $out = [];
        foreach ($raw as $t) {
            $t = strtolower(trim((string) $t));
            if ($t === '' || $t === '1') {
                continue;
            }
            if (! in_array($t, self::TARGETS, true)) {
                throw new InvalidArgumentException('Unknown check "' . $t . '". Choose from: ' . implode(', ', self::TARGETS) . '.');
            }
            $out[] = $t;
        }
        $out = array_values(array_unique($out));
        return $out === [] ? self::TARGETS : array_values(array_filter(self::TARGETS, static fn ($t) => in_array($t, $out, true)));
    }

    /**
     * @param list<string> $targets from parseTargets(); empty = all
     * @param callable(string $target, string $line): void|null $progress long-running steps (render polling)
     * @return array{ok: bool, ran_at: string, passed: int, failed: int, results: list<array{target: string, ok: bool, message: string, detail: array<string, mixed>, duration_ms: int}>}
     */
    /**
     * Keep the outcome of the last live check (per target: ok / skipped,
     * message, DataForSEO balance) for the dashboard's health strip and
     * recommendations. Never stores anything but those fields. Targets not in
     * this run keep their previous result.
     *
     * @param array<string, mixed> $report run()'s result
     */
    public static function remember(array $report): void
    {
        try {
            $model = new \App\Models\SettingModel();
            $prev  = $model->getSetting(\App\Libraries\Dashboard\CommandCentreService::LIVE_CHECK_SETTING);
            $byTarget = [];
            foreach ((is_array($prev) ? ($prev['results'] ?? []) : []) as $r) {
                $byTarget[(string) $r['target']] = $r;
            }
            foreach (($report['results'] ?? []) as $r) {
                $bal = $r['detail']['balance_usd'] ?? null;
                $byTarget[(string) $r['target']] = array_filter([
                    'target'  => (string) $r['target'],
                    'ok'      => (bool) $r['ok'],
                    'skipped' => ! empty($r['skipped']),
                    'message' => mb_substr((string) ($r['message'] ?? ''), 0, 300),
                    'detail'  => is_numeric($bal) ? ['balance_usd' => (float) $bal] : [],
                    'ran_at'  => $report['ran_at'] ?? gmdate('c'),
                ], static fn ($v) => $v !== null);
            }
            $model->setSetting(\App\Libraries\Dashboard\CommandCentreService::LIVE_CHECK_SETTING, [
                'ran_at'  => $report['ran_at'] ?? gmdate('c'),
                'results' => array_values($byTarget),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'LiveIntegrationCheck::remember failed: ' . $e->getMessage());
        }
    }

    public function run(array $targets = [], bool $renderTest = false, ?callable $progress = null): array
    {
        $runAll  = $targets === [];
        $targets = $runAll ? self::TARGETS : $targets;
        $results = [];
        foreach ($targets as $target) {
            $started = microtime(true);
            // A full run skips the direct YouTube uploader while videos go through social-api.ai:
            // its OAuth client is then not needed, and failing on it would hide real failures.
            // `--only=youtube` (or the Video section's own check) still tests it.
            if ($runAll && $target === 'youtube' && YouTubePublisherMode::current() === YouTubePublisherMode::SOCIAL_API) {
                $results[] = [
                    'target'      => 'youtube',
                    'ok'          => true,
                    'skipped'     => true,
                    'message'     => 'Skipped: videos reach YouTube through social-api.ai (Settings → Social), so the direct YouTube login is not used. Run --only=youtube to test it anyway.',
                    'detail'      => ['youtube_publisher' => YouTubePublisherMode::SOCIAL_API],
                    'duration_ms' => 0,
                ];
                continue;
            }
            try {
                $r = match ($target) {
                    'shotstack' => $this->shotstack($renderTest, $progress),
                    'youtube'   => $this->youtube(),
                    'tts'       => $this->tts(),
                    'social_api' => $this->socialApi(),
                    'gemini', 'openai', 'dataforseo' => $this->providers->check($target),
                    default     => $this->result($target, false, 'Unknown check.'),
                };
            } catch (VideoProviderException $e) {
                $r = $this->result($target, false, $e->getMessage(), ['kind' => $e->kind]);
            } catch (Throwable $e) {
                $r = $this->result($target, false, 'Unexpected error (' . (new \ReflectionClass($e))->getShortName() . '): ' . mb_substr($e->getMessage(), 0, 300));
            }
            $results[] = $this->scrub([
                'target'      => $target,
                'ok'          => (bool) $r['ok'],
                'message'     => (string) $r['message'],
                'detail'      => (array) ($r['detail'] ?? []),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }
        $failed  = count(array_filter($results, static fn ($r) => ! $r['ok']));
        $skipped = count(array_filter($results, static fn ($r) => ! empty($r['skipped'])));

        return [
            'ok'      => $failed === 0,
            'ran_at'  => gmdate('c'),
            'passed'  => count($results) - $failed - $skipped,
            'failed'  => $failed,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    // ── Shotstack ───────────────────────────────────────────────────────────

    private function shotstack(bool $renderTest, ?callable $progress): array
    {
        $key = ReachConfig::secret('video.shotstack.api_key');
        if ($key === null) {
            return $this->result('shotstack', false, 'Shotstack API key is not set (Settings → AI & Automation → Video, or SHOTSTACK_API_KEY).');
        }
        $env    = ShotstackClient::environment();
        $detail = ['environment' => $env, 'billing' => $env === 'v1' ? 'production — renders are billed' : 'stage — renders are free and watermarked'];

        $res = $this->http->request('GET', $this->shotstackBase() . '/edit/' . $env . '/templates', ['x-api-key' => $key, 'Accept' => 'application/json'], null, ['timeout' => 20]);
        if ($res->status === 0) {
            return $this->result('shotstack', false, 'Could not reach Shotstack (network error' . $this->why($res->transportError) . ').', $detail);
        }
        if ($res->status === 401 || $res->status === 403) {
            return $this->result('shotstack', false, "Shotstack rejected the key for the {$env} environment (HTTP {$res->status}). Stage and production (v1) keys differ — the key must match video.shotstack.env.", $detail);
        }
        if (! $res->ok()) {
            return $this->result('shotstack', false, VideoProviderException::fromResponse('Shotstack', "{$env} templates", $res)->getMessage(), $detail);
        }
        $templates = $res->json()['response']['templates'] ?? null;
        if (is_array($templates)) {
            $detail['templates'] = count($templates);
        }
        $message = "Key accepted on the {$env} environment (" . ($env === 'v1' ? 'production, billed' : 'stage, free/watermarked') . ').';

        if (! $renderTest) {
            return $this->result('shotstack', true, $message, $detail);
        }

        $render            = $this->shotstackRender($progress);
        $detail['render']  = $render['detail'];
        return $this->result('shotstack', $render['ok'], $message . ' ' . $render['message'], $detail);
    }

    /** @return array{ok: bool, message: string, detail: array<string, mixed>} */
    private function shotstackRender(?callable $progress): array
    {
        $client  = new ShotstackClient($this->http, $this->shotstackBase());
        $started = ($this->clock)();
        $id      = $client->submit(self::renderTestEdit());
        $detail  = ['render_id' => $id, 'length_secs' => self::RENDER_LENGTH_SECS];
        $this->say($progress, 'shotstack', "render {$id} submitted on " . ShotstackClient::environment() . '; polling every ' . self::RENDER_POLL_SECS . ' s (timeout ' . self::RENDER_TIMEOUT_SECS . ' s)');

        $last      = 'queued';
        $lastError = null;
        while (true) {
            ($this->sleep)(self::RENDER_POLL_SECS);
            $elapsed = ($this->clock)() - $started;
            try {
                $status    = $client->status($id);
                $lastError = null;
                $last      = $status->providerStatus !== '' ? $status->providerStatus : $status->status;
                if ($status->done()) {
                    $detail += ['url' => $status->url, 'elapsed_secs' => $elapsed];
                    return ['ok' => true, 'message' => "Test render done in {$elapsed} s: {$status->url}", 'detail' => $detail];
                }
                if ($status->failed()) {
                    $detail['elapsed_secs'] = $elapsed;
                    return ['ok' => false, 'message' => 'Test render failed: ' . ($status->error ?? 'Shotstack gave no reason') . '.', 'detail' => $detail];
                }
                $this->say($progress, 'shotstack', "render {$id}: {$last} ({$elapsed} s)");
            } catch (VideoProviderException $e) {
                if ($e->kind !== VideoProviderException::TRANSIENT) {
                    throw $e;
                }
                // A blip while polling: keep going until the deadline.
                $lastError = $e->getMessage();
                $this->say($progress, 'shotstack', "render {$id}: status check failed, retrying ({$lastError})");
            }
            if ($elapsed >= self::RENDER_TIMEOUT_SECS) {
                $detail['elapsed_secs'] = $elapsed;
                return [
                    'ok'      => false,
                    'message' => "Test render timed out after {$elapsed} s (last status: {$last}" . ($lastError !== null ? "; {$lastError}" : '') . ") — render id {$id}; look it up in the Shotstack dashboard.",
                    'detail'  => $detail,
                ];
            }
        }
    }

    /**
     * A 3-second title card in the pipeline's own text style — the cheapest
     * render that still exercises fonts, text layout and MP4 output.
     *
     * @return array<string, mixed>
     */
    public static function renderTestEdit(): array
    {
        return [
            'timeline' => [
                'background' => TimelineBuilder::WHITE,
                'tracks'     => [[
                    'clips' => [[
                        'asset' => [
                            'type'      => 'text',
                            'text'      => 'AICOUNTLY Reach — live render check',
                            'width'     => 1100,
                            'height'    => 300,
                            'font'      => ['family' => TimelineBuilder::FONT_DISPLAY, 'color' => TimelineBuilder::NAVY, 'size' => 56, 'lineHeight' => 1.15],
                            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                        ],
                        'start'    => 0,
                        'length'   => self::RENDER_LENGTH_SECS,
                        'position' => 'center',
                    ]],
                ]],
            ],
            'output' => ['format' => 'mp4', 'fps' => 25, 'size' => ['width' => 1280, 'height' => 720]],
        ];
    }

    private function shotstackBase(): string
    {
        return rtrim((string) (getenv('SHOTSTACK_BASE_URL') ?: 'https://api.shotstack.io'), '/');
    }

    // ── YouTube ─────────────────────────────────────────────────────────────

    private function youtube(): array
    {
        if (! YouTubeOAuth::clientConfigured()) {
            return $this->result('youtube', false, 'YouTube OAuth client is not configured (video.youtube.client_id / client_secret in Settings → AI & Automation → Video).');
        }
        if (! YouTubeOAuth::hasRefreshToken()) {
            return $this->result('youtube', false, 'No YouTube channel is connected (no refresh token) — click “Connect YouTube channel” in Reach → Video.');
        }

        $oauth = new YouTubeOAuth($this->http);
        $this->extraSecrets[] = $oauth->accessToken();
        $scopes  = $oauth->grantedScopes();
        $missing = $scopes === null ? [] : array_values(array_filter(YouTubeOAuth::SCOPES, static fn ($s) => ! in_array($s, explode(' ', $scopes), true)));

        $item    = (new YouTubeDataClient($this->http, $oauth))->channelResource('snippet,status');
        $status  = (array) ($item['status'] ?? []);
        $long    = (string) ($status['longUploadsStatus'] ?? 'unknown');
        $privacy = strtolower((string) ReachConfig::get('video.youtube.privacy'));

        $detail = [
            'channel_id'          => (string) $item['id'],
            'channel_title'       => (string) ($item['snippet']['title'] ?? ''),
            'channel_privacy'     => (string) ($status['privacyStatus'] ?? 'unknown'),
            'long_uploads'        => $long,
            'custom_thumbnails'   => $long === 'allowed'
                ? 'likely allowed — the channel is verified (long uploads allowed); the API does not report this directly'
                : 'not reported by the API; YouTube allows custom thumbnails once the channel is phone-verified (youtube.com/verify)',
            'made_for_kids'       => isset($status['madeForKids']) ? (bool) $status['madeForKids'] : null,
            'scopes'              => $scopes === null ? 'not reported' : implode(' ', array_map(static fn ($s) => basename($s), explode(' ', $scopes))),
            'upload_privacy'      => $privacy,
            'upload_privacy_note' => self::uploadPrivacyNote($privacy),
        ];

        if ($missing !== []) {
            return $this->result('youtube', false, 'Token refreshed, but Google did not grant ' . implode(', ', $missing) . ' — reconnect the channel in Reach → Video and tick every box.', $detail);
        }
        if ($detail['channel_privacy'] !== 'public' && $detail['channel_privacy'] !== 'unknown') {
            $detail['warning'] = 'The channel itself is ' . $detail['channel_privacy'] . '.';
        }

        return $this->result('youtube', true, sprintf(
            'Token refresh OK; channel “%s” (%s). Long uploads: %s. Uploads will be requested as %s.',
            $detail['channel_title'],
            $detail['channel_id'],
            $long,
            $privacy,
        ), $detail);
    }

    public static function uploadPrivacyNote(string $privacy): string
    {
        $note = 'Reach asks YouTube for privacyStatus=' . $privacy . ' (Settings → Video → YouTube privacy). ';
        return $note . 'YouTube locks every video uploaded through an unverified Google Cloud API project to private until the project passes the YouTube API audit; '
            . 'the API does not reveal whether that lock applies, so check the first upload’s visibility in YouTube Studio (and the OAuth consent screen’s verification status in Google Cloud).';
    }

    // ── OpenAI TTS ──────────────────────────────────────────────────────────

    private function tts(): array
    {
        if (ReachConfig::secret('ai.openai.api_key') === null) {
            return $this->result('tts', false, 'OpenAI API key is not set (Settings → AI & Automation → AI providers) — voice-overs need it.');
        }
        $client = new OpenAiTtsClient($this->http);
        $body   = $client->requestBody(self::TTS_PHRASE);
        $bytes  = $client->synthesize(self::TTS_PHRASE);
        $size   = strlen($bytes);
        $ms     = Mp3Duration::milliseconds($bytes);
        unset($bytes); // nothing is kept

        return $this->result('tts', true, sprintf(
            'Synthesised %s bytes of MP3%s with %s / %s.',
            number_format($size),
            $ms !== null ? sprintf(' (%.1f s)', $ms / 1000) : '',
            $body['model'],
            $body['voice'],
        ), ['bytes' => $size, 'duration_ms' => $ms, 'model' => $body['model'], 'voice' => $body['voice']]);
    }

    // ── social-api.ai ───────────────────────────────────────────────────────

    private function socialApi(): array
    {
        $key = ReachConfig::secret('social.socialapi.api_key');
        if ($key === null) {
            return $this->result('social_api', false, 'social-api.ai API key is not set (Settings → AI & Automation → Social, or SOCIALAPI_API_KEY).');
        }
        $client   = new SocialApiAiClient($key, (string) ReachConfig::get('social.socialapi.base_url'), $this->http, static function (int $s): void {
        }, getRetries: 0);
        $detail   = ['base_url' => $client->baseUrl(), 'youtube_publisher' => YouTubePublisherMode::current()];
        $accounts = new SocialApiAccounts($client, null, null, false);

        try {
            $all = $accounts->all(true);
        } catch (SocialApiException $e) {
            $message = match ($e->kind) {
                SocialApiException::AUTH      => "social-api.ai rejected the API key (HTTP {$e->httpStatus}). Check social.socialapi.api_key (it starts with sapi_key_).",
                SocialApiException::TRANSIENT => $e->httpStatus === 0
                    ? 'Could not reach social-api.ai at ' . $client->baseUrl() . ' (network error). ' . $e->getMessage()
                    : "social-api.ai answered HTTP {$e->httpStatus}. " . $e->getMessage(),
                default                       => $e->getMessage(),
            };
            return $this->result('social_api', false, $message, $detail + ['kind' => $e->kind, 'http_status' => $e->httpStatus]);
        }

        $counts = [];
        $lines  = [];
        foreach ($all as $a) {
            $p = $a['platform'] ?? ('unsupported:' . ($a['platform_raw'] ?? '?'));
            $counts[$p] = ($counts[$p] ?? 0) + 1;
            $lines[] = trim($p . ' ' . (($a['name'] ?? null) !== null ? '“' . $a['name'] . '” ' : '') . (($a['username'] ?? null) !== null ? '@' . ltrim((string) $a['username'], '@') . ' ' : '') . 'id=' . $a['id'] . ' status=' . ($a['status'] ?? 'n/a') . ($a['active'] ? '' : ' (not active)'));
        }
        $detail['accounts'] = $lines === [] ? 'none connected' : $lines;

        $targets = [];
        foreach (SocialApiPlatforms::PLATFORMS as $platform) {
            $r = $accounts->check($platform);
            $targets[$platform] = $r['ok']
                ? 'posts go to ' . $r['account']['id'] . (($r['account']['name'] ?? null) !== null ? ' “' . $r['account']['name'] . '”' : '') . ' (' . ($r['account']['source'] === 'setting' ? 'social.socialapi.account.' . $platform : 'auto: the only active account') . ')'
                : 'not available — ' . $r['reason'];
        }
        $detail['targets'] = $targets;

        $summary = $counts === [] ? 'no accounts connected' : implode(', ', array_map(static fn ($p, $n) => "{$p} {$n}", array_keys($counts), $counts));
        $ready   = array_keys(array_filter($targets, static fn ($t) => str_starts_with($t, 'posts go to')));

        $message = sprintf(
            'Key accepted; %d connected account(s): %s. Ready to post to: %s. Nothing was posted.',
            count($all),
            $summary,
            $ready === [] ? 'none' : implode(', ', $ready),
        );

        // Social inbox bot: confirm the (assumed) inbox list endpoint, read-only.
        if (! (bool) ReachConfig::get('social.inbox.enabled', true)) {
            $detail['inbox'] = 'not checked — the social inbox bot is off (social.inbox.enabled)';
            return $this->result('social_api', true, $message, $detail);
        }
        $probe           = $this->socialInboxProbe($client, $all);
        $detail['inbox'] = $probe['detail'];

        return $this->result('social_api', $probe['ok'], $message . ' ' . $probe['line'], $detail);
    }

    /** Inbox paths tried read-only when the configured one answers 404 (to tell the operator which exists). */
    public const INBOX_PROBE_ALTERNATIVES = ['/inbox', '/inbox/comments', '/inbox/conversations', '/inbox/messages', '/comments', '/conversations', '/messages'];

    /**
     * GET the inbox list once for one active account (never posts, never
     * replies). On a 404, the usual alternative paths are tried (GET only) and
     * the ones that answer are named, so the setting can be corrected.
     *
     * @param list<array<string, mixed>> $accounts
     * @return array{ok: bool, line: string, detail: array<string, mixed>}
     */
    private function socialInboxProbe(SocialApiAiClient $client, array $accounts): array
    {
        $path   = SocialApiAiClient::path((string) ReachConfig::get('social.inbox.list_path', SocialApiAiClient::INBOX_LIST_PATH), SocialApiAiClient::INBOX_LIST_PATH);
        $wanted = \App\Libraries\Distribution\SocialInbox\SocialInboxService::platforms();
        $acc    = null;
        foreach ($accounts as $a) {
            if ($a['active'] && in_array($a['platform'], $wanted, true)) {
                $acc = $a;
                break;
            }
        }
        $detail = ['list_path' => $path];
        if ($acc === null) {
            return ['ok' => true, 'line' => 'Inbox: not checked — no active account of the networks it reads (' . implode(', ', $wanted) . ').', 'detail' => $detail + ['result' => 'not checked']];
        }
        $detail['account'] = $acc['platform'] . ' ' . $acc['id'];
        $query = ['account_id' => (string) $acc['id'], 'limit' => 5, 'since' => gmdate('Y-m-d\TH:i:s\Z', ($this->clock)() - 7 * 86400)];
        try {
            $json = $client->inbox($query, $path);
        } catch (SocialApiException $e) {
            if ($e->httpStatus !== 404) {
                return ['ok' => false, 'line' => "Inbox list FAILED (GET {$path}): " . $e->getMessage(), 'detail' => $detail + ['result' => 'failed', 'http_status' => $e->httpStatus]];
            }
            $found = [];
            foreach (self::INBOX_PROBE_ALTERNATIVES as $alt) {
                if ($alt === $path) {
                    continue;
                }
                try {
                    $altJson = $client->inbox($query, $alt);
                    $p = \App\Libraries\Distribution\SocialInbox\SocialInboxParser::page($altJson, $acc['platform'], (string) $acc['id']);
                    $found[] = $alt . ($p['recognised'] ? ' (' . count($p['items']) . ' item(s))' : ' (answered, shape not recognised)');
                } catch (SocialApiException) {
                }
            }
            return ['ok' => false, 'line' => "Inbox list FAILED: GET {$path} is HTTP 404 — the path is an assumption. " . ($found !== []
                ? 'These answered: ' . implode(', ', $found) . '. Set social.inbox.list_path to the right one (docs.social-api.ai/guides/inbox).'
                : 'None of ' . implode(', ', array_diff(self::INBOX_PROBE_ALTERNATIVES, [$path])) . ' answered either; read docs.social-api.ai/guides/inbox and set social.inbox.list_path.'),
                'detail' => $detail + ['result' => 'not found (404)', 'alternatives_answering' => $found === [] ? 'none' : $found]];
        }

        $page = \App\Libraries\Distribution\SocialInbox\SocialInboxParser::page($json, $acc['platform'], (string) $acc['id']);
        if (! $page['recognised']) {
            return ['ok' => false, 'line' => "Inbox list answered (GET {$path}) but with a shape Reach does not recognise (top-level keys: " . implode(', ', $page['keys']) . '). Nothing will be read until the parser matches it.',
                'detail' => $detail + ['result' => 'unrecognised shape', 'keys' => implode(', ', $page['keys'])]];
        }
        $first = $page['items'][0] ?? null;
        $fields = $first !== null ? array_slice(array_map('strval', array_keys((array) $first['raw'])), 0, 15) : [];

        return ['ok' => true, 'line' => sprintf('Inbox list OK (GET %s): %d item(s) on the first page%s. Nothing was replied to.', $path, count($page['items']), $page['next'] !== null ? ', more pages' : ''), 'detail' => $detail + [
            'result'        => 'ok',
            'items'         => count($page['items']),
            'dropped_items' => count($page['skipped']),
            'item_fields'   => $fields === [] ? 'no items to inspect' : implode(', ', $fields),
            'first_item'    => $first !== null ? sprintf('%s %s, %d characters, received %s', $first['platform'] ?? '?', $first['kind'], mb_strlen((string) $first['text']), $first['received_at'] ?? 'time not given') : null,
        ]];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{ok: bool, target: string, message: string, detail: array<string, mixed>} */
    private function result(string $target, bool $ok, string $message, array $detail = []): array
    {
        return ['ok' => $ok, 'target' => $target, 'message' => $message, 'detail' => $detail];
    }

    private function why(?string $error): string
    {
        return $error !== null && $error !== '' ? ': ' . mb_substr($error, 0, 160) : '';
    }

    private function say(?callable $progress, string $target, string $line): void
    {
        if ($progress !== null) {
            $progress($target, $this->scrubString($line));
        }
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function scrub(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->scrub($v);
            } elseif (is_string($v)) {
                $value[$k] = $this->scrubString($v);
            }
        }
        return $value;
    }

    private function scrubString(string $text): string
    {
        foreach ($this->secrets() as $secret) {
            $text = str_replace($secret, '[REDACTED]', $text);
        }
        return preg_replace('/(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i', '$1 [REDACTED]', $text) ?? $text;
    }

    /** @return list<string> */
    private function secrets(): array
    {
        $out = $this->extraSecrets;
        foreach (ReachConfig::registry() as $key => $meta) {
            if (($meta['type'] ?? null) === 'secret') {
                $v = ReachConfig::secret($key);
                if ($v !== null) {
                    $out[] = $v;
                    $out[] = rawurlencode($v);
                }
            }
        }
        $login = trim((string) ReachConfig::get('seo.dataforseo.login', ''));
        $pass  = ReachConfig::secret('seo.dataforseo.password');
        if ($login !== '' && $pass !== null) {
            $out[] = base64_encode($login . ':' . $pass);
        }
        $out = array_values(array_unique(array_filter($out, static fn ($s) => strlen((string) $s) >= 6)));
        usort($out, static fn ($a, $b) => strlen($b) <=> strlen($a));
        return $out;
    }
}

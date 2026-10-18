<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiPlatforms;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiPostResult;
use App\Libraries\Settings\ReachConfig;

/**
 * social-api.ai publisher — social posts and YouTube videos / Shorts.
 *
 * Selected by ChannelProviderFactory when `social.socialapi.api_key` is set
 * (Settings → AI & Automation → Social, or SOCIALAPI_API_KEY).
 *
 * publish():
 *   1. account for the platform (SocialApiAccounts: setting override, else the
 *      single active account; none / ambiguous → ProviderNotConfiguredException)
 *   2. media: each file in media_refs is fetched (streamed to a temp file),
 *      GET /media/upload-url → PUT to the presigned URL → POST /media/{id}/verify
 *   3. POST /posts {text, title?, targets:[{account_id}], media, publish_now: true
 *      | scheduled_at, platform_data?} — always publish_now or scheduled_at, never
 *      a draft. platform_data is the operator's `social.socialapi.platform_data.<platform>`
 *      JSON, merged verbatim (Reach invents no platform keys).
 *   4. 201 → receipt status `submitted` (delivery happens in the background);
 *      207 / 422 / 400 / 409 / 401 / 429 / 5xx → an exception with the error code.
 *
 * A post is only ever reported published by getStatus(), from GET /posts/{id}
 * (SocialPostStatusPoller). No webhook receiver: social-api.ai's webhook
 * signature scheme is not documented to Reach, so verifyCallback() refuses.
 */
class SocialApiAiPublisher implements SocialPublisherInterface
{
    public const PROVIDER = 'social_api';

    public const VERIFY_ATTEMPTS   = 4;
    public const VERIFY_PAUSE_SECS = 3;
    public const MAX_IMAGES        = 10;
    /** A scheduled_at closer than this is sent as publish_now (avoids "scheduled_at in the past"). */
    public const SCHEDULE_MIN_LEAD_SECS = 120;

    private SocialApiAiClient $client;
    private SocialApiAccounts $accounts;
    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $baseUrl = null,
        ?SocialApiAiClient $client = null,
        ?SocialApiAccounts $accounts = null,
        ?callable $sleep = null,
    ) {
        $this->sleep    = $sleep ?? static function (int $secs): void {
            sleep($secs);
        };
        $this->client   = $client ?? new SocialApiAiClient($apiKey, $baseUrl, null, $this->sleep);
        $this->accounts = $accounts ?? new SocialApiAccounts($this->client);
    }

    public function client(): SocialApiAiClient
    {
        return $this->client;
    }

    public function accounts(): SocialApiAccounts
    {
        return $this->accounts;
    }

    public function publish(ChannelMessage $message, string $platform): ProviderReceipt
    {
        $target = SocialApiPlatforms::forChannel($platform);
        if ($target === null) {
            throw new SocialApiException("Reach channel “{$platform}” cannot be posted through social-api.ai. Nothing was posted.", SocialApiException::PERMANENT, 422);
        }
        $account = $this->account($target);
        $media   = $this->uploadMedia($message);
        if (isset(SocialApiPlatforms::MEDIA_REQUIRED[$target]) && $media === []) {
            throw new SocialApiException(
                SocialApiPlatforms::label($target) . ' needs ' . SocialApiPlatforms::MEDIA_REQUIRED[$target] . '; this post has no media file Reach can fetch (media_refs). Nothing was posted.',
                SocialApiException::MEDIA_SOURCE,
            );
        }

        $body   = $this->postBody($message, $target, $account['id'], $media);
        $result = $this->client->createPost($body, $message->idempotencyKey);

        return $this->receipt($result, $target, $account, $media, $body);
    }

    /**
     * The request body for POST /posts (public for tests and diagnosis).
     *
     * @param list<string> $mediaIds
     * @return array<string, mixed>
     */
    public function postBody(ChannelMessage $message, string $platform, string $accountId, array $mediaIds): array
    {
        $text  = trim($message->content);
        $tags  = self::hashtags($message->metadata['hashtags'] ?? null);
        $extra = array_values(array_filter($tags, static fn ($t) => ! str_contains($text, $t)));
        if ($extra !== []) {
            $text .= ($text !== '' ? "\n\n" : '') . implode(' ', $extra);
        }

        $body  = ['text' => $text];
        $title = trim((string) ($message->metadata['title'] ?? ''));
        if ($title === '' && $platform === 'youtube') {
            $title = trim((string) strtok($text, "\n"));
        }
        if ($title !== '') {
            $body['title'] = mb_substr($title, 0, $platform === 'youtube' ? 100 : 255);
        }
        $body['targets'] = [self::target($platform, $accountId)];
        if ($mediaIds !== []) {
            $body['media'] = array_map(static fn (string $id): array => ['source_type' => 'media_id', 'media_id' => $id], $mediaIds);
        }

        // Never a draft: always publish_now, or scheduled_at when it is really in the future.
        $when = self::futureTime($message->metadata['scheduled_at'] ?? null);
        if ($when !== null) {
            $body['scheduled_at'] = $when;
        } else {
            $body['publish_now'] = true;
        }

        $platformData = self::mergeDeep(
            ReachConfig::jsonObject('social.socialapi.platform_data.' . $platform),
            is_array($message->metadata['platform_data'] ?? null) ? $message->metadata['platform_data'] : [],
        );
        if ($platformData !== []) {
            $body['platform_data'] = $platformData;
        }

        return $body;
    }

    /**
     * One targets[] entry. Per-network options live on the target, not in platform_data:
     * social-api.ai's own dashboard sends YouTube visibility as targets[].visibility, and
     * its default is private, so Reach always sends the configured visibility for YouTube.
     *
     * @return array<string, mixed>
     */
    public static function target(string $platform, string $accountId): array
    {
        $target = ReachConfig::jsonObject('social.socialapi.target_options.' . $platform);
        if ($platform === 'youtube') {
            $visibility = strtolower(trim((string) ReachConfig::get('social.socialapi.youtube.visibility', 'public')));
            $target['visibility'] = in_array($visibility, ['public', 'unlisted', 'private'], true) ? $visibility : 'public';
        }
        $target['account_id'] = $accountId;

        return ['account_id' => $accountId] + $target;
    }

    public function getStatus(string $providerPostId): ProviderStatus
    {
        return $this->statusFor($providerPostId, null);
    }

    /**
     * GET /posts/{id}, reduced to the target Reach sent:
     *   normalisedStatus  published | failed | pending
     *   failureClass      the target's error.code (e.g. publishing.delivery.timeout)
     *   raw               post_status, target (url, external_id, error), targets, response (redacted)
     */
    public function statusFor(string $providerPostId, ?string $accountId): ProviderStatus
    {
        $result  = $this->client->post($providerPostId);
        $outcome = $result->outcomeFor($accountId);
        $target  = $result->target($accountId);

        return new ProviderStatus(
            providerMessageId: $providerPostId,
            normalisedStatus:  match ($outcome) {
                SocialApiPostResult::PUBLISHED => 'published',
                SocialApiPostResult::FAILED    => 'failed',
                default                        => 'pending',
            },
            statusAt:     new \DateTimeImmutable(),
            failureClass: $outcome === SocialApiPostResult::FAILED ? ($target['error_code'] ?? 'publishing.failed') : null,
            raw:          [
                'post_status'     => $result->rawStatus,
                'provider_status' => $target['raw_status'] ?? $result->rawStatus,
                'target'          => $target,
                'targets'         => $result->targets,
                'response'        => $result->raw,
            ],
        );
    }

    /**
     * Not implemented: Reach was not given social-api.ai's delete endpoint.
     * Refuses rather than pretending the post is gone.
     */
    public function withdraw(string $providerPostId): bool
    {
        throw new ProviderNotConfiguredException('Withdrawing a post is not implemented for social-api.ai (no confirmed delete endpoint). Remove it in social-api.ai or on the network.', 501);
    }

    public function getCapabilities(): array
    {
        $platforms   = [];
        $unavailable = [];
        foreach (SocialApiPlatforms::CHANNELS as $channel) {
            $r = $this->readiness($channel);
            if ($r['ok']) {
                $platforms[] = $channel;
            } else {
                $unavailable[$channel] = $r['reason'];
            }
        }

        return [
            'platforms'   => $platforms,
            'unavailable' => $unavailable,
            'media_types' => ['image', 'video'],
            'base_url'    => $this->client->baseUrl(),
            'credentials' => $this->apiKey !== '' ? 'set' : 'missing',
            'webhooks'    => false,
            'status'      => 'polling (GET /posts/{id})',
        ];
    }

    /**
     * Can a post on this Reach channel go out right now? (key + base URL set,
     * channel maps to a platform, and an account resolves).
     *
     * @return array{ok: bool, platform: ?string, account: ?array<string, mixed>, reason: ?string, kind: ?string}
     */
    public function readiness(string $channel): array
    {
        $platform = SocialApiPlatforms::forChannel($channel);
        if ($platform === null) {
            return ['ok' => false, 'platform' => null, 'account' => null, 'reason' => "social-api.ai does not carry the {$channel} channel.", 'kind' => 'unsupported'];
        }
        if ($this->apiKey === '') {
            return ['ok' => false, 'platform' => $platform, 'account' => null, 'reason' => 'social.socialapi.api_key is not set.', 'kind' => SocialApiException::NOT_CONFIGURED];
        }

        return ['platform' => $platform] + $this->accounts->check($platform);
    }

    public function isEnabled(): bool
    {
        return $this->apiKey !== '' && $this->client->baseUrl() !== '';
    }

    /** No webhook receiver: the signature scheme is not known, so nothing unsigned is trusted. */
    public function verifyCallback(array $headers, string $rawBody): bool
    {
        return false;
    }

    public function providerName(): string
    {
        return self::PROVIDER;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** @return array{id: string, name: ?string, username: ?string, source: string} */
    private function account(string $platform): array
    {
        try {
            return $this->accounts->resolve($platform);
        } catch (SocialApiException $e) {
            if (in_array($e->kind, [SocialApiException::NOT_CONFIGURED, SocialApiException::AUTH], true)) {
                throw new ProviderNotConfiguredException($e->getMessage(), 503, $e);
            }
            throw $e;
        }
    }

    /**
     * Upload the post's media files; returns the verified media ids.
     *
     * @return list<string>
     */
    private function uploadMedia(ChannelMessage $message): array
    {
        $files = self::mediaFiles($message->mediaRefs);
        $ids   = [];
        foreach ($files as $i => $ref) {
            $ids[] = $this->uploadOne($ref, $message->idempotencyKey . ':' . $i);
        }
        return $ids;
    }

    /** @param array{source: string, local: bool, type: ?string, mime: ?string} $ref */
    private function uploadOne(array $ref, string $key): string
    {
        $tmp = null;
        try {
            if ($ref['local']) {
                $file = $ref['source'];
                if (! is_file($file) || ! is_readable($file)) {
                    throw new SocialApiException('The media file to attach is missing on the server. Nothing was posted.', SocialApiException::MEDIA_SOURCE);
                }
                $mime = $ref['mime'] ?? self::mimeFor($file, $ref['type']);
            } else {
                $tmp  = self::tempFile();
                $got  = $this->client->download($ref['source'], $tmp);
                $file = $tmp;
                if ($got['bytes'] <= 0) {
                    throw new SocialApiException('The media file to attach was empty (' . (parse_url($ref['source'], PHP_URL_HOST) ?: 'unknown host') . '). Nothing was posted.', SocialApiException::MEDIA_SOURCE);
                }
                $mime = $ref['mime'] ?? (self::usableMime($got['mime']) ? $got['mime'] : self::mimeFor((string) parse_url($ref['source'], PHP_URL_PATH), $ref['type']));
            }

            $name   = 'reach-' . substr(sha1($key), 0, 12) . '.' . self::extensionFor($mime);
            $upload = $this->client->uploadUrl($mime, $name);
            $this->client->putMedia($upload, $file, $mime);
            $this->verify($upload['media_id']);

            return $upload['media_id'];
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function verify(string $mediaId): void
    {
        $status = null;
        for ($i = 1; $i <= self::VERIFY_ATTEMPTS; $i++) {
            $status = $this->client->verifyMedia($mediaId)['status'];
            $norm   = SocialApiPostResult::normalizeStatus($status);
            $word   = strtolower((string) $status);
            if ($status === null || in_array($word, ['ready', 'verified', 'uploaded', 'available', 'active', 'ok', 'valid'], true) || $norm === SocialApiPostResult::PUBLISHED) {
                return;
            }
            if ($norm === SocialApiPostResult::FAILED || in_array($word, ['invalid', 'missing', 'not_found', 'corrupt'], true)) {
                throw new SocialApiException("social-api.ai rejected the uploaded media file (status “{$status}”). Nothing was posted.", SocialApiException::REJECTED, 422, 'media.' . $word);
            }
            if ($norm !== SocialApiPostResult::PENDING) {
                // A word Reach does not know: say so rather than guess it is ready.
                throw new SocialApiException("social-api.ai answered the media verify with status “{$status}”, which Reach does not recognise. Nothing was posted.", SocialApiException::UNRECOGNISED, 200);
            }
            if ($i < self::VERIFY_ATTEMPTS) {
                ($this->sleep)(self::VERIFY_PAUSE_SECS);
            }
        }
        throw new SocialApiException("The uploaded media is still “{$status}” at social-api.ai after " . self::VERIFY_ATTEMPTS . ' checks; will retry. Nothing was posted.', SocialApiException::TRANSIENT, 503);
    }

    /**
     * @param array{id: string, name: ?string, username: ?string, source: string} $account
     * @param list<string>         $media
     * @param array<string, mixed> $body
     */
    private function receipt(SocialApiPostResult $result, string $platform, array $account, array $media, array $body): ProviderReceipt
    {
        $s      = $result->httpStatus;
        $target = $result->target($account['id']);
        $label  = SocialApiPlatforms::label($platform);
        $meta   = [
            'platform'       => $platform,
            'account_id'     => $account['id'],
            'account_source' => $account['source'],
            'media_ids'      => $media,
            'http_status'    => $s,
            'publish_mode'   => isset($body['scheduled_at']) ? 'scheduled' : 'publish_now',
            'scheduled_at'   => $body['scheduled_at'] ?? null,
            'response'       => $result->raw,
        ];
        $err  = SocialApiException::errorOf($result->raw);
        $fail = static fn (string $msg, string $kind, ?string $code = null): SocialApiException => new SocialApiException($msg, $kind, $s, $code ?? $err['code'], $result->raw, $result->targets);

        if ($s === 401 || $s === 403) {
            throw new ProviderNotConfiguredException("social-api.ai rejected the API key (HTTP {$s}) — check social.socialapi.api_key and run php spark reach:integrations-check --only=social_api. Nothing was posted.", 503, $fail('auth', SocialApiException::AUTH));
        }
        if ($s === 409) {
            if ($result->postId !== null) {
                // The duplicate social-api.ai refused is a post it already has: follow that one.
                return new ProviderReceipt($result->postId, 'submitted', new \DateTimeImmutable(), null, json_encode($result->raw, JSON_UNESCAPED_SLASHES) ?: null, $meta + ['duplicate_of_existing' => true]);
            }
            throw $fail("social-api.ai refused the post as a duplicate (" . ($err['code'] ?? 'resource.conflict') . "): it already has a post with the same title and text from the last 24 hours, so nothing new was posted. Check social-api.ai for that post; change the text to post again.", SocialApiException::CONFLICT, $err['code'] ?? 'resource.conflict');
        }
        if ($s === 400) {
            throw $fail("social-api.ai refused the request (" . ($err['code'] ?? 'validation') . ')' . ($err['message'] ? ': ' . $err['message'] : '') . '. Nothing was posted.', SocialApiException::VALIDATION);
        }
        if ($s === 422) {
            $why = $result->failureSummary() ?: trim(($err['code'] ?? '') . ' ' . ($err['message'] ?? ''));
            throw $fail("social-api.ai could not publish to {$label}" . ($why !== '' ? ": {$why}" : '') . '.', SocialApiException::REJECTED, $target['error_code'] ?? $err['code']);
        }
        if ($s === 429) {
            throw $fail('social-api.ai rate limit reached (HTTP 429); try again later. Nothing was posted.', SocialApiException::RATE_LIMIT);
        }
        if ($s === 0 || $s >= 500) {
            throw $fail('social-api.ai did not confirm the post (' . ($s === 0 ? 'network error' : "HTTP {$s}") . '). Outcome unknown: it may or may not have been created — check social-api.ai before retrying (a retry within 24 h of a created post is refused as a duplicate).', SocialApiException::TRANSIENT);
        }
        if ($s < 200 || $s >= 300) {
            throw $fail("social-api.ai answered HTTP {$s}" . ($err['message'] ? ': ' . $err['message'] : '') . '. Nothing was posted.', SocialApiException::PERMANENT);
        }

        // 2xx, including 207 partial.
        if ($target !== null && $target['status'] === SocialApiPostResult::FAILED) {
            throw $fail("social-api.ai could not publish to {$label}: " . ($target['error_code'] ?? 'failed') . ($target['error_message'] ? ' — ' . $target['error_message'] : '') . '.', SocialApiException::REJECTED, $target['error_code'] ?? 'publishing.failed');
        }
        if ($result->postId === null) {
            if (function_exists('log_message')) {
                log_message('warning', '[SocialApiAi] POST /posts HTTP ' . $s . ' without a post id Reach recognises; keys: ' . implode(', ', array_slice(array_map('strval', array_keys($result->raw)), 0, 12)));
            }
            throw $fail("social-api.ai accepted the post (HTTP {$s}) but returned no post id Reach recognises, so its delivery cannot be tracked. It may still be published — check social-api.ai (the raw response is stored on the post).", SocialApiException::UNRECOGNISED, 'reach.response_unrecognised');
        }

        return new ProviderReceipt($result->postId, 'submitted', new \DateTimeImmutable(), $target['url'] ?? null, json_encode($result->raw, JSON_UNESCAPED_SLASHES) ?: null, $meta);
    }

    /**
     * Files to attach from media_refs: the first video (Shorts/Reels/YouTube
     * carry one), otherwise up to MAX_IMAGES images. Links to YouTube (the
     * `youtube_short` ref) are not files and are skipped — the text carries them.
     *
     * @param array<int|string, mixed> $refs
     * @return list<array{source: string, local: bool, type: ?string, mime: ?string}>
     */
    public static function mediaFiles(array $refs): array
    {
        $videos = [];
        $images = [];
        foreach ($refs as $ref) {
            if (is_string($ref)) {
                $ref = ['url' => $ref];
            }
            if (! is_array($ref)) {
                continue;
            }
            $kind = strtolower((string) ($ref['kind'] ?? ''));
            $url  = trim((string) ($ref['url'] ?? $ref['src'] ?? ''));
            $path = trim((string) ($ref['path'] ?? $ref['file'] ?? ''));
            if ($kind === 'youtube_short' || $kind === 'youtube' || ($url !== '' && preg_match('#^https?://(www\.|m\.)?(youtube\.com|youtu\.be)/#i', $url))) {
                continue;
            }
            $source = $path !== '' ? $path : $url;
            if ($source === '') {
                continue;
            }
            if ($path === '' && ! preg_match('#^https?://#i', $url)) {
                throw new SocialApiException('A media file is referenced by a URL Reach cannot fetch (' . (parse_url($url, PHP_URL_SCHEME) ?: 'no scheme') . '://…). Nothing was posted.', SocialApiException::MEDIA_SOURCE);
            }
            $mime = isset($ref['mime']) && self::usableMime((string) $ref['mime']) ? strtolower((string) $ref['mime']) : null;
            $type = strtolower((string) ($ref['type'] ?? ''));
            if (! in_array($type, ['video', 'image'], true)) {
                $guess = $mime ?? self::mimeFor((string) (parse_url($source, PHP_URL_PATH) ?? $source), null);
                $type  = str_starts_with($guess, 'video/') ? 'video' : (str_starts_with($guess, 'image/') ? 'image' : '');
            }
            $entry = ['source' => $source, 'local' => $path !== '', 'type' => $type ?: null, 'mime' => $mime];
            if ($type === 'video') {
                $videos[] = $entry;
            } elseif ($type === 'image') {
                $images[] = $entry;
            }
        }
        if ($videos !== []) {
            return [$videos[0]];
        }
        return array_slice($images, 0, self::MAX_IMAGES);
    }

    /** @return list<string> */
    private static function hashtags(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $raw);
        }
        $out = [];
        foreach ((array) $raw as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            $out[] = str_starts_with($t, '#') ? $t : '#' . $t;
        }
        return array_values(array_unique($out));
    }

    private static function futureTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false || $ts < time() + self::SCHEDULE_MIN_LEAD_SECS) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function mergeDeep(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && ! array_is_list($v) && ! array_is_list($base[$k])) {
                $base[$k] = self::mergeDeep($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function usableMime(?string $mime): bool
    {
        return $mime !== null && preg_match('#^(video|image)/[a-z0-9.+\-]+$#i', $mime) === 1;
    }

    private static function mimeFor(string $path, ?string $type): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp4', 'm4v' => 'video/mp4',
            'mov'        => 'video/quicktime',
            'webm'       => 'video/webm',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            'gif'        => 'image/gif',
            'webp'       => 'image/webp',
            default      => $type === 'image' ? 'image/jpeg' : ($type === 'video' ? 'video/mp4' : 'application/octet-stream'),
        };
    }

    private static function extensionFor(string $mime): string
    {
        return match (strtolower($mime)) {
            'video/mp4'       => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm'      => 'webm',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'image/jpeg'      => 'jpg',
            default           => 'bin',
        };
    }

    private static function tempFile(): string
    {
        $dir = defined('WRITEPATH') ? rtrim(WRITEPATH, '/') . '/cache' : sys_get_temp_dir();
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/socialapi-' . bin2hex(random_bytes(8)) . '.part';
    }
}

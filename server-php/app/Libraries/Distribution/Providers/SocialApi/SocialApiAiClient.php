<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers\SocialApi;

use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\VideoProviders;

/**
 * HTTP client for social-api.ai (https://api.social-api.ai/v1).
 *
 * What the docs say (docs.social-api.ai; this build could not reach them, the
 * owner supplied the facts):
 *   auth        Authorization: Bearer sapi_key_…; JSON bodies; RFC 3339 times
 *   accounts    GET  /accounts                      connected Account objects
 *   media       GET  /media/upload-url?media_type=&filename=  → {media_id, upload_url}
 *               PUT  <upload_url> (bytes, matching Content-Type)
 *               POST /media/{id}/verify             required; pending → ready
 *   posts       POST /posts                         201 created / 207 partial / 422 all
 *                                                   failed / 400 validation / 409 duplicate
 *               GET  /posts/{id}                    the post with targets[] statuses
 *
 * Retries: only idempotent GETs (accounts, upload-url, post status) are
 * retried, and only on a network error or 5xx. POST /posts is never retried
 * here: it carries an `Idempotency-Key` header (ASSUMPTION: harmless if
 * social-api.ai ignores it — the docs Reach had do not mention it), and the
 * caller relies on Reach's own row state plus social-api.ai's 409 duplicate
 * check (same title + text within 24 h) instead.
 *
 * The API key is only ever sent to the configured base URL — never to a
 * presigned upload URL or a media source.
 */
final class SocialApiAiClient
{
    public const DEFAULT_BASE_URL = 'https://api.social-api.ai/v1';

    public const TIMEOUT_SECS        = 20;
    public const MEDIA_TIMEOUT_SECS  = 300;
    public const GET_RETRIES         = 2;

    private readonly string $baseUrl;
    private readonly HttpTransport $http;
    private readonly SocialApiRedactor $redactor;
    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        ?HttpTransport $http = null,
        ?callable $sleep = null,
        private readonly int $getRetries = self::GET_RETRIES,
    ) {
        $base          = trim((string) $baseUrl);
        $this->baseUrl = rtrim($base !== '' ? $base : self::DEFAULT_BASE_URL, '/');
        $this->http    = $http ?? VideoProviders::http();
        $this->sleep   = $sleep ?? static function (int $secs): void {
            sleep($secs);
        };
        $this->redactor = new SocialApiRedactor([$apiKey]);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function redactor(): SocialApiRedactor
    {
        return $this->redactor;
    }

    /** Fingerprint of key + base URL, for caches (never the key itself). */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->apiKey . '|' . $this->baseUrl), 0, 20);
    }

    /**
     * GET /accounts → the raw Account objects.
     *
     * @return list<array<string, mixed>>
     * @throws SocialApiException
     */
    public function accounts(): array
    {
        $res  = $this->get('/accounts');
        $json = $this->decode($res);
        if (! $res->ok()) {
            throw $this->error('list accounts', $res, $json);
        }
        $list = array_is_list($json) ? $json : null;
        if ($list === null) {
            foreach (['data', 'accounts', 'items', 'results'] as $k) {
                if (isset($json[$k]) && is_array($json[$k]) && array_is_list($json[$k])) {
                    $list = $json[$k];
                    break;
                }
            }
        }
        if ($list === null) {
            $this->log('GET /accounts returned an unrecognised shape (top-level keys: ' . implode(', ', array_slice(array_map('strval', array_keys($json)), 0, 12)) . ').');
            throw new SocialApiException('social-api.ai answered GET /accounts with a shape Reach does not recognise (expected a list of accounts). Nothing was assumed.', SocialApiException::UNRECOGNISED, $res->status, null, (array) $this->redactor->value($json));
        }
        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * GET /media/upload-url → media id + presigned upload URL (+ any headers it asks for).
     *
     * @return array{media_id: string, upload_url: string, headers: array<string, string>, method: string}
     */
    public function uploadUrl(string $mime, string $filename): array
    {
        $res  = $this->get('/media/upload-url?' . http_build_query(['media_type' => $mime, 'filename' => $filename], '', '&', PHP_QUERY_RFC3986));
        $json = $this->decode($res);
        if (! $res->ok()) {
            throw $this->error('media upload-url', $res, $json);
        }
        $body = isset($json['data']) && is_array($json['data']) ? $json['data'] + $json : $json;
        $id   = $body['media_id'] ?? $body['id'] ?? $body['mediaId'] ?? null;
        $url  = $body['upload_url'] ?? $body['uploadUrl'] ?? $body['url'] ?? null;
        if (! is_scalar($id) || (string) $id === '' || ! is_string($url) || ! preg_match('#^https://#i', $url)) {
            $this->log('GET /media/upload-url returned no media_id/upload_url (keys: ' . implode(', ', array_slice(array_map('strval', array_keys($body)), 0, 12)) . ').');
            throw new SocialApiException('social-api.ai did not return a media id and an https upload URL.', SocialApiException::UNRECOGNISED, $res->status, null, (array) $this->redactor->value($json));
        }
        $headers = [];
        foreach ((array) ($body['headers'] ?? $body['upload_headers'] ?? []) as $k => $v) {
            if (is_string($k) && is_scalar($v) && strcasecmp($k, 'authorization') !== 0) {
                $headers[$k] = (string) $v;
            }
        }
        $method = strtoupper((string) ($body['method'] ?? $body['upload_method'] ?? 'PUT'));

        return ['media_id' => (string) $id, 'upload_url' => $url, 'headers' => $headers, 'method' => in_array($method, ['PUT', 'POST'], true) ? $method : 'PUT'];
    }

    /**
     * Send the file to the presigned URL. No Authorization header: the URL is
     * the credential, and Reach's key must not reach the storage host.
     *
     * @param array{upload_url: string, headers?: array<string, string>, method?: string} $target
     */
    public function putMedia(array $target, string $file, string $mime): void
    {
        $size    = (int) @filesize($file);
        $headers = ['Content-Type' => $mime, 'Content-Length' => (string) $size] + ($target['headers'] ?? []);
        $method  = $target['method'] ?? 'PUT';
        $res     = null;
        for ($attempt = 0; $attempt <= 1; $attempt++) {
            $res = $this->http->request($method, $target['upload_url'], $headers, null, ['timeout' => self::MEDIA_TIMEOUT_SECS, 'body_file' => $file]);
            if ($res->ok() || ($res->status !== 0 && $res->status < 500)) {
                break;
            }
            ($this->sleep)(2);
        }
        if (! $res->ok()) {
            $detail = $res->transportError !== null ? 'network error: ' . mb_substr($res->transportError, 0, 160) : 'HTTP ' . $res->status;
            throw new SocialApiException('Uploading the media file to social-api.ai storage failed (' . $this->redactor->string($detail) . ').', $res->status >= 500 || $res->status === 0 ? SocialApiException::TRANSIENT : SocialApiException::REJECTED, $res->status);
        }
    }

    /**
     * POST /media/{id}/verify → the media status as reported (raw string, may be null).
     *
     * @return array{status: ?string, raw: array<string, mixed>}
     */
    public function verifyMedia(string $mediaId): array
    {
        $res  = $this->request('POST', '/media/' . rawurlencode($mediaId) . '/verify', '{}');
        $json = $this->decode($res);
        if (! $res->ok()) {
            throw $this->error('media verify', $res, $json);
        }
        $body   = isset($json['data']) && is_array($json['data']) ? $json['data'] + $json : $json;
        $status = $body['status'] ?? $body['state'] ?? null;

        return ['status' => is_string($status) ? $status : null, 'raw' => (array) $this->redactor->value($json)];
    }

    /**
     * POST /posts — never retried. Returns the parsed answer for every HTTP
     * status the API documents (2xx, 207, 400, 409, 422); the caller decides.
     *
     * @param array<string, mixed> $body
     */
    public function createPost(array $body, ?string $idempotencyKey = null): SocialApiPostResult
    {
        $extra = [];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $extra['Idempotency-Key'] = substr(hash('sha256', $idempotencyKey), 0, 40);
        }
        $res = $this->request('POST', '/posts', (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $extra);

        return SocialApiPostResult::parse($res->status, $this->decode($res) + ($res->transportError !== null ? ['transport_error' => $this->redactor->string($res->transportError)] : []), $this->redactor);
    }

    /** GET /posts/{id}. */
    public function post(string $postId): SocialApiPostResult
    {
        $res  = $this->get('/posts/' . rawurlencode($postId));
        $json = $this->decode($res);
        if (! $res->ok()) {
            throw $this->error('post status', $res, $json);
        }
        return SocialApiPostResult::parse($res->status, $json, $this->redactor);
    }

    /**
     * Fetch a media source (e.g. the rendered MP4) into a local file, streamed.
     * No Reach credentials are sent.
     *
     * @return array{bytes: int, mime: ?string}
     */
    public function download(string $url, string $toFile): array
    {
        $res = $this->http->request('GET', $url, ['Accept' => '*/*'], null, ['timeout' => self::MEDIA_TIMEOUT_SECS, 'sink_file' => $toFile]);
        if (! $res->ok()) {
            $detail = $res->transportError !== null ? 'network error: ' . mb_substr($res->transportError, 0, 160) : 'HTTP ' . $res->status;
            throw new SocialApiException('Could not fetch the media file to attach (' . $this->redactor->string($detail) . ' from ' . (parse_url($url, PHP_URL_HOST) ?: 'its host') . ').', $res->status === 0 || $res->status >= 500 ? SocialApiException::TRANSIENT : SocialApiException::MEDIA_SOURCE, $res->status);
        }
        clearstatcache(true, $toFile);
        $bytes = is_file($toFile) ? (int) filesize($toFile) : 0;
        $type  = $res->header('content-type');

        return ['bytes' => $bytes, 'mime' => $type !== null ? strtolower(trim(explode(';', $type)[0])) : null];
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function get(string $path): HttpResponse
    {
        $res = $this->request('GET', $path);
        for ($i = 0; $i < $this->getRetries && ($res->status === 0 || $res->status >= 500); $i++) {
            ($this->sleep)($i + 1);
            $res = $this->request('GET', $path);
        }
        return $res;
    }

    /** @param array<string, string> $extraHeaders */
    private function request(string $method, string $path, ?string $body = null, array $extraHeaders = []): HttpResponse
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'] + $extraHeaders;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        return $this->http->request($method, $this->baseUrl . $path, $headers, $body, ['timeout' => self::TIMEOUT_SECS]);
    }

    /** @return array<string, mixed>|list<mixed> */
    private function decode(HttpResponse $res): array
    {
        $decoded = json_decode($res->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $json */
    private function error(string $operation, HttpResponse $res, array $json): SocialApiException
    {
        $err  = SocialApiException::errorOf($json);
        $kind = SocialApiException::kindForStatus($res->status);
        $why  = match (true) {
            $res->status === 0                     => 'network error' . ($res->transportError ? ': ' . mb_substr($res->transportError, 0, 160) : ''),
            $kind === SocialApiException::AUTH     => "HTTP {$res->status}: the API key was refused — check social.socialapi.api_key",
            default                                => "HTTP {$res->status}",
        };
        $detail = trim(($err['code'] ?? '') . ($err['message'] ? ' ' . $err['message'] : ''));
        $msg    = "social-api.ai {$operation} failed ({$why})" . ($detail !== '' ? ': ' . $detail : '') . '.';

        return new SocialApiException($this->redactor->string($msg), $kind, $res->status, $err['code'], (array) $this->redactor->value($json));
    }

    private function log(string $message): void
    {
        if (function_exists('log_message')) {
            log_message('warning', '[SocialApiAi] ' . $this->redactor->string($message));
        }
    }
}

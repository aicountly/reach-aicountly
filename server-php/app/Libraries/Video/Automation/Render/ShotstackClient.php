<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Render;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Http\CurlTransport;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\VideoProviderException;

/**
 * Shotstack Edit API: POST {base}/edit/{stage|v1}/render with the JSON edit,
 * GET {base}/edit/{env}/render/{id} for status. Key in the `x-api-key`
 * header (video.shotstack.api_key); environment from video.shotstack.env.
 * Rendered files are hosted by Shotstack for about 24 hours.
 */
final class ShotstackClient implements RenderClient
{
    private HttpTransport $http;

    public function __construct(?HttpTransport $http = null, private readonly ?string $baseUrl = null)
    {
        $this->http = $http ?? new CurlTransport();
    }

    public function name(): string
    {
        return 'shotstack:' . self::environment();
    }

    public static function environment(): string
    {
        $env = strtolower(trim((string) ReachConfig::get('video.shotstack.env')));
        return in_array($env, ['v1', 'stage'], true) ? $env : 'stage';
    }

    public static function isConfigured(): bool
    {
        return ReachConfig::secret('video.shotstack.api_key') !== null;
    }

    public function submit(array $edit): string
    {
        $res = $this->http->request('POST', $this->endpoint('/render'), $this->headers(), json_encode($edit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['timeout' => 60]);
        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('Shotstack', 'render submit', $res);
        }
        $id = (string) ($res->json()['response']['id'] ?? '');
        if ($id === '' || ! preg_match('/^[A-Za-z0-9\-]{8,80}$/', $id)) {
            throw new VideoProviderException('Shotstack accepted the render but returned no render id.', VideoProviderException::TRANSIENT);
        }
        return $id;
    }

    public function status(string $renderId): RenderResult
    {
        if (! preg_match('/^[A-Za-z0-9\-]{8,80}$/', $renderId)) {
            throw new VideoProviderException('Invalid Shotstack render id.', VideoProviderException::PERMANENT);
        }
        $res = $this->http->request('GET', $this->endpoint('/render/' . $renderId . '?data=false'), $this->headers(), null, ['timeout' => 30]);
        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('Shotstack', 'render status', $res);
        }
        $r   = (array) ($res->json()['response'] ?? []);
        $raw = strtolower((string) ($r['status'] ?? ''));

        $status = match ($raw) {
            'done'                                  => 'done',
            'failed'                                => 'failed',
            'queued'                                => 'queued',
            'fetching', 'preprocessing', 'rendering', 'saving' => 'rendering',
            default                                 => 'rendering',
        };
        $url = isset($r['url']) && is_string($r['url']) && str_starts_with($r['url'], 'https://') ? $r['url'] : null;
        if ($status === 'done' && $url === null) {
            throw new VideoProviderException('Shotstack reports the render done but gave no output URL.', VideoProviderException::TRANSIENT);
        }

        return new RenderResult(
            status: $status,
            providerStatus: $raw,
            url: $url,
            posterUrl: is_string($r['poster'] ?? null) ? $r['poster'] : null,
            thumbnailUrl: is_string($r['thumbnail'] ?? null) ? $r['thumbnail'] : null,
            error: is_string($r['error'] ?? null) && $r['error'] !== '' ? mb_substr($r['error'], 0, 500) : null,
            durationSecs: isset($r['duration']) && is_numeric($r['duration']) ? (float) $r['duration'] : null,
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $key = ReachConfig::secret('video.shotstack.api_key');
        if ($key === null) {
            throw VideoProviderException::notConfigured('Shotstack API key is not set (Settings → AI & Automation → Video) — videos cannot be rendered.');
        }
        return ['x-api-key' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    private function endpoint(string $path): string
    {
        $base = rtrim($this->baseUrl ?? (string) (getenv('SHOTSTACK_BASE_URL') ?: 'https://api.shotstack.io'), '/');
        return $base . '/edit/' . self::environment() . $path;
    }
}

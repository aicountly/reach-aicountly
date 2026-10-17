<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Visuals;

use App\Libraries\Video\Automation\Http\CurlTransport;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\VideoProviderException;
use App\Libraries\WorkerPlaywrightClient;

/**
 * Product screenshots through the Worker's Playwright screenshot endpoint
 * (WorkerPlaywrightClient → POST v1/screenshot). The image comes back either
 * inline (base64) or as a URL to fetch; both shapes are accepted.
 */
final class WorkerScreenshotSource implements ScreenshotSource
{
    private HttpTransport $http;

    public function __construct(?HttpTransport $http = null, private readonly ?WorkerPlaywrightClient $worker = null)
    {
        $this->http = $http ?? new CurlTransport();
    }

    public function capture(string $url, int $width, int $height): string
    {
        $worker = $this->worker ?? \Config\Services::workerClient();
        if (! $worker->isConfigured()) {
            throw VideoProviderException::notConfigured('Screenshot worker is not configured (WORKER_BASE_URL / WORKER_API_TOKEN).');
        }

        $res = $worker->screenshot([
            'url'       => $url,
            'viewport'  => ['width' => $width, 'height' => $height],
            'full_page' => false,
            'format'    => 'png',
            'wait_until' => 'networkidle',
            'purpose'   => 'reach-video',
        ]);
        if (! $res['ok']) {
            throw new VideoProviderException('Screenshot worker failed for ' . $url . ': ' . mb_substr((string) $res['error'], 0, 200),
                ($res['status'] ?? 0) >= 500 || ($res['status'] ?? 0) === 0 ? VideoProviderException::TRANSIENT : VideoProviderException::PERMANENT);
        }

        $data = (array) ($res['data'] ?? []);
        $data = isset($data['data']) && is_array($data['data']) ? $data['data'] + $data : $data;
        foreach (['image_base64', 'png_base64', 'screenshot_base64', 'base64', 'image'] as $field) {
            if (is_string($data[$field] ?? null) && $data[$field] !== '') {
                $b64   = preg_replace('#^data:image/[a-z]+;base64,#i', '', $data[$field]) ?? '';
                $bytes = base64_decode($b64, true);
                if ($bytes !== false && @getimagesizefromstring($bytes) !== false) {
                    return $bytes;
                }
            }
        }
        foreach (['url', 'image_url', 'screenshot_url'] as $field) {
            if (is_string($data[$field] ?? null) && str_starts_with($data[$field], 'https://')) {
                $img = $this->http->request('GET', $data[$field], [], null, ['timeout' => 60]);
                if ($img->ok() && @getimagesizefromstring($img->body) !== false) {
                    return $img->body;
                }
            }
        }

        throw new VideoProviderException('Screenshot worker returned no image for ' . $url . '.', VideoProviderException::PERMANENT);
    }
}

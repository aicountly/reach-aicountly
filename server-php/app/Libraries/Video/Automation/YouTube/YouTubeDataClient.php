<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\YouTube;

use App\Libraries\Video\Automation\Http\CurlTransport;
use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\VideoProviderException;

/**
 * YouTube Data API v3.
 *
 * Uploads use the resumable protocol so a cPanel cron step (no long-running
 * workers) can move a few chunks at a time: each chunk is read from the
 * rendered MP4 at Shotstack with an HTTP Range request and PUT to the upload
 * session with a Content-Range header. Google answers 308 (with the bytes it
 * holds) until the last chunk, then 200/201 with the video resource.
 *
 * Quota: videos.insert ≈ 1,600 units, thumbnails.set 50, captions.insert 400
 * of the default 10,000 units per day.
 */
final class YouTubeDataClient implements YouTubeClient
{
    /** Must be a multiple of 256 KiB. */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/youtube/v3';
    private const API_BASE    = 'https://www.googleapis.com/youtube/v3';

    private HttpTransport $http;

    public function __construct(?HttpTransport $http = null, private readonly ?YouTubeOAuth $oauth = null, private readonly int $chunkBytes = self::CHUNK_BYTES)
    {
        $this->http = $http ?? new CurlTransport();
    }

    public function name(): string
    {
        return 'youtube';
    }

    public function startUpload(array $resource, int $bytes, string $mime = 'video/mp4'): string
    {
        if ($bytes <= 0) {
            throw new VideoProviderException('Cannot upload an empty video.', VideoProviderException::PERMANENT);
        }
        $res = $this->http->request('POST', self::UPLOAD_BASE . '/videos?uploadType=resumable&part=snippet,status&notifySubscribers=true', $this->auth() + [
            'Content-Type'            => 'application/json; charset=UTF-8',
            'X-Upload-Content-Length' => (string) $bytes,
            'X-Upload-Content-Type'   => $mime,
        ], json_encode($resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['timeout' => 60]);

        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('YouTube', 'upload start', $res);
        }
        $location = (string) $res->header('location');
        if (! str_starts_with($location, 'https://')) {
            throw new VideoProviderException('YouTube opened no upload session (no Location header).', VideoProviderException::TRANSIENT);
        }
        return $location;
    }

    public function continueUpload(string $sessionUrl, string $sourceUrl, int $total, int $offset, int $deadline): UploadProgress
    {
        // Resync with what Google actually holds: a previous step may have
        // died between sending a chunk and saving the offset.
        $state = $this->queryUpload($sessionUrl, $total);
        if ($state->complete) {
            return $state;
        }
        $offset = $state->offset;

        while ($offset < $total) {
            $end   = min($offset + $this->chunkBytes, $total) - 1;
            $chunk = $this->readRange($sourceUrl, $offset, $end, $total);

            $res = $this->http->request('PUT', $sessionUrl, $this->auth() + [
                'Content-Length' => (string) strlen($chunk),
                'Content-Range'  => "bytes {$offset}-{$end}/{$total}",
                'Content-Type'   => 'video/mp4',
            ], $chunk, ['timeout' => 300]);

            $progress = $this->interpret($res, $total, 'upload chunk');
            if ($progress->complete) {
                return $progress;
            }
            if ($progress->offset <= $offset) {
                throw new VideoProviderException("YouTube did not accept bytes {$offset}-{$end}; will resume.", VideoProviderException::TRANSIENT);
            }
            $offset = $progress->offset;
            if (time() >= $deadline) {
                break;
            }
        }

        return new UploadProgress($offset, $total, false);
    }

    public function sourceSize(string $sourceUrl): int
    {
        $res = $this->http->request('HEAD', $sourceUrl, [], null, ['timeout' => 30]);
        if ($res->status === 403 || $res->status === 404) {
            throw new VideoProviderException('The rendered video is no longer available at the renderer (expired).', VideoProviderException::SOURCE_EXPIRED);
        }
        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('Renderer output', 'HEAD', $res);
        }
        $size = (int) ($res->header('content-length') ?? 0);
        if ($size <= 0) {
            throw new VideoProviderException('The rendered video reported no size.', VideoProviderException::TRANSIENT);
        }
        return $size;
    }

    public function setThumbnail(string $videoId, string $bytes, string $mime): void
    {
        $res = $this->http->request('POST', self::UPLOAD_BASE . '/thumbnails/set?videoId=' . rawurlencode($videoId) . '&uploadType=media', $this->auth() + [
            'Content-Type'   => $mime,
            'Content-Length' => (string) strlen($bytes),
        ], $bytes, ['timeout' => 60]);
        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('YouTube', 'thumbnails.set', $res);
        }
    }

    public function insertCaption(string $videoId, string $language, string $name, string $srt): string
    {
        $boundary = 'reach' . bin2hex(random_bytes(12));
        $meta     = json_encode(['snippet' => ['videoId' => $videoId, 'language' => $language, 'name' => $name, 'isDraft' => false]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body     = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n"
            . "--{$boundary}\r\nContent-Type: application/octet-stream\r\n\r\n{$srt}\r\n"
            . "--{$boundary}--\r\n";

        $res = $this->http->request('POST', self::UPLOAD_BASE . '/captions?part=snippet&uploadType=multipart', $this->auth() + [
            'Content-Type' => 'multipart/related; boundary=' . $boundary,
        ], $body, ['timeout' => 60]);
        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('YouTube', 'captions.insert', $res);
        }
        return (string) ($res->json()['id'] ?? '');
    }

    public function channel(): array
    {
        $item = $this->channelResource('snippet');
        return ['id' => (string) $item['id'], 'title' => (string) ($item['snippet']['title'] ?? '')];
    }

    /**
     * The connected channel's resource (channels.list mine=true) with the
     * given parts, e.g. "snippet,status". Read-only; 1 quota unit.
     *
     * @return array<string, mixed>
     */
    public function channelResource(string $parts = 'snippet'): array
    {
        if (! preg_match('/^[a-zA-Z]+(,[a-zA-Z]+)*$/', $parts)) {
            throw new VideoProviderException('Invalid channels.list parts.', VideoProviderException::PERMANENT);
        }
        $res = $this->http->request('GET', self::API_BASE . '/channels?part=' . $parts . '&mine=true', $this->auth() + ['Accept' => 'application/json'], null, ['timeout' => 30]);
        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('YouTube', 'channels.list', $res);
        }
        $item = $res->json()['items'][0] ?? null;
        if (! is_array($item) || ($item['id'] ?? '') === '') {
            throw new VideoProviderException('This Google account has no YouTube channel. Create one, then connect again.', VideoProviderException::PERMANENT);
        }
        return $item;
    }

    private function queryUpload(string $sessionUrl, int $total): UploadProgress
    {
        $res = $this->http->request('PUT', $sessionUrl, $this->auth() + [
            'Content-Length' => '0',
            'Content-Range'  => "bytes */{$total}",
        ], '', ['timeout' => 30]);
        return $this->interpret($res, $total, 'upload status');
    }

    private function interpret(HttpResponse $res, int $total, string $operation): UploadProgress
    {
        if ($res->status === 200 || $res->status === 201) {
            $video = $res->json();
            $id    = (string) ($video['id'] ?? '');
            if ($id === '') {
                throw new VideoProviderException('YouTube finished the upload but returned no video id.', VideoProviderException::TRANSIENT);
            }
            return new UploadProgress($total, $total, true, $id, $video);
        }
        if ($res->status === 308) {
            $range = (string) ($res->header('range') ?? '');
            $next  = preg_match('/bytes=0-(\d+)/', $range, $m) ? ((int) $m[1]) + 1 : 0;
            return new UploadProgress($next, $total, false);
        }
        if ($res->status === 404 || $res->status === 410) {
            throw new VideoProviderException('The YouTube upload session expired; a new one will be opened.', VideoProviderException::SESSION_EXPIRED);
        }
        throw VideoProviderException::fromResponse('YouTube', $operation, $res);
    }

    private function readRange(string $url, int $start, int $end, int $total): string
    {
        $res = $this->http->request('GET', $url, ['Range' => "bytes={$start}-{$end}"], null, ['timeout' => 300]);
        $want = $end - $start + 1;
        if ($res->status === 206 && strlen($res->body) === $want) {
            return $res->body;
        }
        // A server that ignores Range sends the whole file; take our slice.
        if ($res->status === 200 && strlen($res->body) === $total) {
            return substr($res->body, $start, $want);
        }
        if ($res->status === 403 || $res->status === 404) {
            throw new VideoProviderException('The rendered video is no longer available at the renderer (expired).', VideoProviderException::SOURCE_EXPIRED);
        }
        throw new VideoProviderException("Could not read bytes {$start}-{$end} of the rendered video (HTTP {$res->status}).", VideoProviderException::TRANSIENT);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . ($this->oauth ?? new YouTubeOAuth($this->http))->accessToken()];
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Libraries\Media\VideoMediaStore;
use CodeIgniter\Controller;

/**
 * GET v1/public/video-media/{uuid}.{ext}?exp=&sig= — public, signed and
 * expiring. This is how Shotstack fetches voice-overs and stills; it honours
 * a single HTTP Range (renderers seek in audio files).
 */
class VideoMediaController extends Controller
{
    public function serve(string $file = '')
    {
        $uuid = preg_replace('/\.(mp3|jpg|png)$/i', '', $file) ?? '';
        $exp  = (int) $this->request->getGet('exp');
        $sig  = (string) $this->request->getGet('sig');
        if ($uuid === '' || $sig === '' || ! VideoMediaStore::verify($uuid, $exp, $sig)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false]);
        }

        $media = (new VideoMediaStore())->findByUuid($uuid);
        $path  = (string) ($media['file_path'] ?? '');
        if ($media === null || $path === '' || ! is_file($path)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false]);
        }

        $size = (int) filesize($path);
        $this->response
            ->setHeader('Content-Type', (string) $media['mime'])
            ->setHeader('Accept-Ranges', 'bytes')
            ->setHeader('Cache-Control', 'private, max-age=3600');

        $range = (string) $this->request->getHeaderLine('Range');
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
                $end   = $size - 1;
            } else {
                $start = (int) $m[1];
                $end   = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            }
            if ($start >= $size || $start > $end) {
                return $this->response->setStatusCode(416)->setHeader('Content-Range', "bytes */{$size}")->setBody('');
            }
            $fh = fopen($path, 'rb');
            fseek($fh, $start);
            $body = (string) fread($fh, $end - $start + 1);
            fclose($fh);

            return $this->response->setStatusCode(206)
                ->setHeader('Content-Range', "bytes {$start}-{$end}/{$size}")
                ->setHeader('Content-Length', (string) strlen($body))
                ->setBody($body);
        }

        return $this->response->setHeader('Content-Length', (string) $size)->setBody((string) file_get_contents($path));
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Content;

use App\Libraries\Media\MediaAssetStore;
use CodeIgniter\Controller;

/**
 * Public, signed serving of stored cover images.
 *
 * This is the URL handed to aicountly.com as featured_image_url; its
 * HeroImageFetcher requires a directly-reachable 200 (no auth, no redirect,
 * public IP, < 2 MB), so this route lives OUTSIDE the jwt group and is
 * protected by a non-expiring HMAC signature over the asset uuid instead.
 */
class MediaAssetController extends Controller
{
    public function serve(string $uuidWithExtension = '')
    {
        $uuid = preg_replace('/\.(webp|img|png|jpg)$/i', '', $uuidWithExtension) ?? '';
        $sig  = (string) $this->request->getGet('sig');

        if ($uuid === '' || $sig === '' || ! MediaAssetStore::verifySignature($uuid, $sig)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false]);
        }

        $asset = (new MediaAssetStore())->findByUuid($uuid);
        if ($asset === null || ($asset['status'] ?? '') !== 'active' || ! is_file((string) $asset['file_path'])) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false]);
        }

        return $this->response
            ->setHeader('Content-Type', (string) $asset['mime'])
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setHeader('Content-Length', (string) filesize((string) $asset['file_path']))
            ->setBody((string) file_get_contents((string) $asset['file_path']));
    }
}

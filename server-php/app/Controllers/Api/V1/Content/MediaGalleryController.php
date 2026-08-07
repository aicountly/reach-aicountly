<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Content;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Media\MediaAssetStore;
use App\Libraries\Media\MediaGalleryDeficitService;

/**
 * Cover-image gallery management (Quality Centre). Uploads are recompressed
 * to WebP under the public-site hero cap and deduplicated by checksum.
 */
class MediaGalleryController extends BaseApiController
{
    public function index()
    {
        $db     = db_connect();
        $status = trim((string) $this->request->getGet('status'));
        $kind   = trim((string) $this->request->getGet('kind'));

        $builder = $db->table('reach_media_gallery_assets')->orderBy('id', 'DESC')->limit(200);
        if ($status !== '') {
            $builder->where('status', $status);
        }
        if ($kind !== '') {
            $builder->where('kind', $kind);
        }

        $store = new MediaAssetStore($db);
        $rows  = array_map(static function (array $row) use ($store): array {
            // Computed before file_path is stripped: a row whose binary is gone
            // renders as a broken <img> with no clue why, which is how a
            // missing upload directory hides for weeks.
            $row['file_missing'] = ! is_file((string) ($row['file_path'] ?? ''));
            unset($row['file_path']);
            $row['public_url'] = $store->publicUrl($row);

            return $row;
        }, $builder->get()->getResultArray());

        // Serving is HMAC-signed and fails closed: with no MEDIA_SIGNING_KEY,
        // publicUrl() still mints a URL but verifySignature() rejects every
        // request, so all covers 404 — in this gallery AND on aicountly.com.
        // Report it rather than leaving an operator to guess from broken tiles.
        return $this->ok([
            'assets'                 => $rows,
            'signing_key_configured' => trim((string) env('MEDIA_SIGNING_KEY', '')) !== '',
            // Active rows only. A retired asset with no file is settled
            // business — reconcile already dealt with it, and counting it
            // keeps telling the operator to run a command they just ran.
            'files_missing'          => count(array_filter(
                $rows,
                static fn (array $r): bool => $r['file_missing'] && ($r['status'] ?? '') === 'active'
            )),
            'storage_path'           => $store->storagePath(),
            'storage_writable'       => $store->storageWritable(),
            // The effective ceiling, not the app's aspiration. PHP silently
            // discards $_FILES when the body exceeds post_max_size, so a UI
            // promising 4 MB over a 2 MB php.ini produces "field is required"
            // for a file the operator can plainly see attached.
            'max_upload_bytes'       => self::maxUploadBytes(),
            // Storing under the deploy tree is what let rsync --delete erase
            // every cover; say so while it is still the case.
            'storage_outside_deploy' => ! str_starts_with($store->storagePath(), rtrim(ROOTPATH, '/')),
        ]);
    }

    /** Application cap; the effective limit is the smaller of this and php.ini. */
    private const APP_MAX_UPLOAD_BYTES = 4 * 1024 * 1024;

    public function upload()
    {
        $file = $this->request->getFile('file');

        if ($file === null) {
            // PHP drops $_FILES wholesale when the request body exceeds
            // post_max_size — no error code, no file, and $_POST empty too.
            // Distinguishing that from a genuinely absent field is the
            // difference between "the server is misconfigured for the size you
            // sent" and "you forgot to attach something".
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMax       = self::iniBytes('post_max_size');

            if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
                return $this->fail(sprintf(
                    'The upload was %s but this server accepts at most %s per request '
                    . '(PHP post_max_size). The file never reached the application. '
                    . 'Raise post_max_size and upload_max_filesize, or upload a smaller image.',
                    self::humanBytes($contentLength),
                    self::humanBytes($postMax),
                ), 413);
            }

            return $this->fail('Multipart field "file" is required.', 422);
        }

        if (! $file->isValid()) {
            // getErrorString() turns UPLOAD_ERR_INI_SIZE into something an
            // operator can act on instead of a bare "invalid file".
            return $this->fail('Upload failed: ' . $file->getErrorString(), 422);
        }

        $max = self::maxUploadBytes();
        if ($file->getSize() > $max) {
            return $this->fail(sprintf(
                'File is %s; the limit here is %s.',
                self::humanBytes((int) $file->getSize()),
                self::humanBytes($max),
            ), 422);
        }

        $binary = (string) file_get_contents($file->getTempName());
        if ($binary === '' || getimagesizefromstring($binary) === false) {
            return $this->fail('File is not a readable image.', 422);
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $this->request->getPost('tags')))));

        $db       = db_connect();
        $store    = new MediaAssetStore($db);
        $checksumBefore = $db->table('reach_media_gallery_assets')->countAllResults();

        try {
            $asset = $store->storeBinary($binary, [
                'kind'             => 'gallery_upload',
                'prompt_used'      => trim((string) $this->request->getPost('prompt_used')) ?: null,
                'category_tags'    => $tags,
                'portfolio_stream' => trim((string) $this->request->getPost('portfolio_stream')) ?: null,
                'created_by'       => $this->userId(),
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Image processing failed: ' . $e->getMessage(), 422);
        }

        $isDuplicate = $db->table('reach_media_gallery_assets')->countAllResults() === $checksumBefore;
        if ($isDuplicate) {
            return $this->fail('Duplicate image: an identical asset already exists (id ' . $asset['id'] . ').', 409);
        }

        unset($asset['file_path']);
        $asset['public_url'] = $store->publicUrl($asset);

        return $this->ok(['asset' => $asset], 201);
    }

    public function update(int $id)
    {
        $body   = $this->input();
        $db     = db_connect();
        $update = [];

        if (isset($body['status']) && in_array($body['status'], ['active', 'retired'], true)) {
            $update['status'] = $body['status'];
        }
        if (isset($body['category_tags']) && is_array($body['category_tags'])) {
            $update['category_tags'] = json_encode(array_values(array_map('strval', $body['category_tags'])), JSON_UNESCAPED_SLASHES);
        }
        if (isset($body['portfolio_stream'])) {
            $update['portfolio_stream'] = trim((string) $body['portfolio_stream']) ?: null;
        }
        if ($update === []) {
            return $this->fail('Nothing to update (status, category_tags, portfolio_stream).', 422);
        }

        $update['updated_at'] = date('Y-m-d H:i:s');
        $db->table('reach_media_gallery_assets')->where('id', $id)->update($update);

        return $this->ok(['updated' => true]);
    }

    public function deficit()
    {
        return $this->ok((new MediaGalleryDeficitService())->report());
    }

    /**
     * The largest upload that can actually succeed: the application cap, or
     * PHP's limits when they are lower. Both matter — upload_max_filesize
     * rejects the file with an error code, post_max_size discards the whole
     * body before PHP populates $_FILES at all.
     */
    private static function maxUploadBytes(): int
    {
        $limits = array_filter([
            self::APP_MAX_UPLOAD_BYTES,
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ], static fn (int $bytes): bool => $bytes > 0);

        return $limits === [] ? self::APP_MAX_UPLOAD_BYTES : min($limits);
    }

    /** php.ini shorthand ("8M", "1G") in bytes. 0 when unset or unlimited. */
    private static function iniBytes(string $key): int
    {
        $raw = trim((string) ini_get($key));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $value = (int) $raw;
        return match (strtolower(substr($raw, -1))) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1) . ' MB'
            : round($bytes / 1024) . ' KB';
    }
}

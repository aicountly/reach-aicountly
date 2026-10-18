<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Persists cover/gallery images under writable/uploads/media/ and records
 * them in reach_media_gallery_assets. Public serving goes through the signed
 * /api/v1/public/media/{uuid}.webp route — the signature is a non-expiring
 * HMAC of the asset uuid, because the image becomes public on the blog
 * anyway and an expiring URL would break publishes scheduled days ahead.
 */
class MediaAssetStore
{
    private BaseConnection $db;
    private ImagePostProcessor $processor;

    public function __construct(?BaseConnection $db = null, ?ImagePostProcessor $processor = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->processor = $processor ?? new ImagePostProcessor();
    }

    /**
     * @param array{kind?:string, content_item_id?:int|null, prompt_used?:string|null, category_tags?:list<string>, portfolio_stream?:string|null, created_by?:int|null,
     *              source_key?:string|null, provenance?:string|null, branded?:bool, model_key?:string|null, original_binary?:string|null} $meta
     *        original_binary: the unbranded original, kept beside the stored file (original_file_path).
     * @return array<string,mixed> the stored asset row
     */
    public function storeBinary(string $binary, array $meta = []): array
    {
        $processed = $this->processor->toWebUnderCap($binary);
        $checksum  = hash('sha256', $processed['bytes']);

        $existing = $this->db->table('reach_media_gallery_assets')
            ->where('checksum_sha256', $checksum)
            ->get()->getRowArray();
        if ($existing) {
            return $existing;
        }

        $uuid = $this->generateUuid();
        $dir  = $this->baseDir() . date('Y/m');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('media_dir_create_failed');
        }

        $extension = $processed['mime'] === 'image/webp' ? 'webp' : 'img';
        $path      = $dir . '/' . $uuid . '.' . $extension;
        if (file_put_contents($path, $processed['bytes']) === false) {
            throw new \RuntimeException('media_write_failed');
        }

        $original = null;
        if (isset($meta['original_binary']) && is_string($meta['original_binary']) && $meta['original_binary'] !== '') {
            $info     = @getimagesizefromstring($meta['original_binary']);
            $origExt  = match ((string) ($info['mime'] ?? '')) { 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'img' };
            $original = $dir . '/' . $uuid . '.orig.' . $origExt;
            if (file_put_contents($original, $meta['original_binary']) === false) {
                $original = null; // the branded cover is what matters; losing the original is logged, not fatal
                log_message('warning', "MediaAssetStore: could not keep the original for {$uuid}");
            }
        }

        $row = [
            'asset_uuid'       => $uuid,
            'kind'             => (string) ($meta['kind'] ?? 'gallery_upload'),
            'content_item_id'  => $meta['content_item_id'] ?? null,
            'file_path'        => $path,
            'mime'             => $processed['mime'],
            'bytes'            => strlen($processed['bytes']),
            'width'            => $processed['width'],
            'height'           => $processed['height'],
            'checksum_sha256'  => $checksum,
            'prompt_used'      => $meta['prompt_used'] ?? null,
            'category_tags'    => json_encode(array_values($meta['category_tags'] ?? []), JSON_UNESCAPED_SLASHES),
            'portfolio_stream' => $meta['portfolio_stream'] ?? null,
            'status'           => 'active',
            'created_by'       => $meta['created_by'] ?? null,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ];
        // Provenance columns arrive with 2026-10-18-210001_AutoCoverGallery;
        // until it has run, uploads keep working without them.
        if (\App\Libraries\Database\SchemaGuard::hasColumn($this->db, 'reach_media_gallery_assets', 'provenance')) {
            $row += [
                'source_key'         => $meta['source_key'] ?? null,
                'provenance'         => $meta['provenance'] ?? null,
                'branded'            => ! empty($meta['branded']),
                'original_file_path' => $original,
                'model_key'          => $meta['model_key'] ?? null,
            ];
        }
        $this->db->table('reach_media_gallery_assets')->insert($row);
        $row['id'] = (int) $this->db->insertID();

        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[a-f0-9-]{16,64}$/i', $uuid)) {
            return null;
        }

        return $this->db->table('reach_media_gallery_assets')
            ->where('asset_uuid', $uuid)
            ->get()->getRowArray() ?: null;
    }

    /**
     * @param array<string,mixed> $asset
     */
    public function publicUrl(array $asset): string
    {
        // REACH_APP_URL is the portal's public identity and is already
        // required; REACH_PUBLIC_BASE_URL stays as an override for the rare
        // deployment that serves media from a different host.
        $base = rtrim((string) (env('REACH_PUBLIC_BASE_URL')
            ?: env('REACH_APP_URL')
            ?: 'https://reach.aicountly.org'), '/');
        $ext  = $asset['mime'] === 'image/webp' ? 'webp' : 'img';

        return $base . '/api/v1/public/media/' . $asset['asset_uuid'] . '.' . $ext
            . '?sig=' . self::signature((string) $asset['asset_uuid']);
    }

    public static function signature(string $assetUuid): string
    {
        $key = (string) env('MEDIA_SIGNING_KEY', '');
        if ($key === '') {
            // Fail loud in logs but keep a deterministic (unusable) value so
            // callers can detect the misconfiguration by the URL never validating.
            log_message('error', 'MEDIA_SIGNING_KEY is not set; public media URLs will not validate.');
        }

        return hash_hmac('sha256', $assetUuid, $key);
    }

    public static function verifySignature(string $assetUuid, string $signature): bool
    {
        if ((string) env('MEDIA_SIGNING_KEY', '') === '') {
            return false;
        }

        return hash_equals(self::signature($assetUuid), $signature);
    }

    public static function isBranded(array $asset): bool
    {
        $v = $asset['branded'] ?? false;

        return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
    }

    /**
     * Composite the AICOUNTLY corner mark onto an existing, unbranded asset
     * (an upload from before covers were branded). The unbranded file is kept
     * as original_file_path; the public URL (by uuid) does not change. When
     * branding cannot run the asset is returned untouched.
     *
     * @param array<string,mixed> $asset full row
     * @return array<string,mixed>
     */
    public function ensureBranded(array $asset, ?CoverBranding $branding = null): array
    {
        if (self::isBranded($asset) || ! array_key_exists('branded', $asset)) {
            return $asset;
        }
        $path = (string) ($asset['file_path'] ?? '');
        $raw  = is_file($path) ? (string) file_get_contents($path) : '';
        if ($raw === '') {
            return $asset;
        }

        $result = ($branding ?? new CoverBranding())->apply($raw);
        if (! $result['branded']) {
            return $asset;
        }

        try {
            $processed = $this->processor->toWebUnderCap($result['bytes']);
            $original  = preg_replace('/\.(\w+)$/', '.orig.$1', $path) ?: ($path . '.orig');
            if (! @copy($path, $original) || file_put_contents($path, $processed['bytes']) === false) {
                return $asset;
            }
            $update = [
                'branded'            => true,
                'original_file_path' => $original,
                'bytes'              => strlen($processed['bytes']),
                'mime'               => $processed['mime'],
                'width'              => $processed['width'],
                'height'             => $processed['height'],
                'checksum_sha256'    => hash('sha256', $processed['bytes']),
                'updated_at'         => date('Y-m-d H:i:s'),
            ];
            $this->db->table('reach_media_gallery_assets')->where('id', (int) $asset['id'])->update($update);

            return $update + $asset;
        } catch (\Throwable $e) {
            log_message('warning', 'MediaAssetStore: branding asset ' . ($asset['id'] ?? '?') . ' failed: ' . $e->getMessage());

            return $asset;
        }
    }

    public function markUsed(int $assetId, ?int $contentItemId): void
    {
        $this->db->table('reach_media_gallery_assets')->where('id', $assetId)->update([
            'times_used'   => new \CodeIgniter\Database\RawSql('times_used + 1'),
            'last_used_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('reach_media_gallery_usages')->insert([
            'asset_id'        => $assetId,
            'content_item_id' => $contentItemId,
            'used_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Where cover binaries live.
     *
     * Defaults inside the application's writable/ directory, but production
     * should point MEDIA_STORAGE_PATH at a directory OUTSIDE the document root
     * (e.g. /home/<user>/cover_images, alongside the blog_uploads convention).
     * Deploys rsync the API directory with --delete; anything under it is one
     * filter-rule mistake away from being erased, which is exactly how every
     * uploaded cover was deleted while its database row survived. A path
     * outside the deploy tree cannot be reached by that command at all.
     */
    public function storagePath(): string
    {
        $configured = trim((string) env('MEDIA_STORAGE_PATH', ''));

        return $configured !== ''
            ? rtrim($configured, '/') . '/'
            : rtrim(WRITEPATH, '/') . '/uploads/media/covers/';
    }

    /**
     * Can this instance actually store an upload? A configured path that does
     * not exist yet is fine as long as it can be created.
     */
    public function storageWritable(): bool
    {
        $path = rtrim($this->storagePath(), '/');

        if (is_dir($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);

        return is_dir($parent) && is_writable($parent);
    }

    private function baseDir(): string
    {
        return $this->storagePath();
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

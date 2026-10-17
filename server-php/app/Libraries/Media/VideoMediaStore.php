<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Video\Automation\VideoProviderException;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Audio and still images for the video pipeline (MP3 voice-overs, JPEG
 * screenshots / DALL·E stills / thumbnails). Unlike MediaAssetStore these are
 * stored byte-for-byte (no WebP re-encode — the renderer and YouTube want
 * MP3/JPEG) and served through expiring signed URLs with HTTP Range support,
 * so Shotstack can fetch them for a render:
 *
 *   {REACH_APP_URL}/api/v1/public/video-media/{uuid}.{mp3|jpg|png}?exp=…&sig=…
 *
 * Files live under VIDEO_MEDIA_STORAGE_PATH (default writable/uploads/video-media/);
 * point it outside the deploy tree in production, like MEDIA_STORAGE_PATH.
 * Rendered MP4s are never stored here — they stay at Shotstack until YouTube
 * has them.
 */
class VideoMediaStore
{
    public const DEFAULT_TTL_SECS = 7 * 86400;

    private const EXT = ['audio/mpeg' => 'mp3', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array{item_id?: int|null, duration_ms?: int|null, width?: int|null, height?: int|null} $meta
     * @return array<string, mixed> the stored row
     */
    public function store(string $bytes, string $mime, array $meta = []): array
    {
        if (! isset(self::EXT[$mime])) {
            throw new \InvalidArgumentException("Unsupported video media type {$mime}.");
        }
        if ($bytes === '') {
            throw new \InvalidArgumentException('Empty media.');
        }
        $uuid = self::uuid();
        $dir  = $this->storagePath() . gmdate('Y/m');
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('video_media_dir_create_failed');
        }
        $path = $dir . '/' . $uuid . '.' . self::EXT[$mime];
        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('video_media_write_failed');
        }

        $row = [
            'uuid'            => $uuid,
            'kind'            => str_starts_with($mime, 'audio/') ? 'audio' : 'image',
            'mime'            => $mime,
            'file_path'       => $path,
            'bytes'           => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
            'width'           => $meta['width'] ?? null,
            'height'          => $meta['height'] ?? null,
            'duration_ms'     => $meta['duration_ms'] ?? null,
            'item_id'         => $meta['item_id'] ?? null,
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ];
        $this->db->table('reach_video_media')->insert($row);
        $row['id'] = (int) $this->db->insertID();

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->table('reach_video_media')->where('id', $id)->get()->getRowArray() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
            return null;
        }
        return $this->db->table('reach_video_media')->where('uuid', $uuid)->get()->getRowArray() ?: null;
    }

    public function bytes(array $media): ?string
    {
        $path = (string) ($media['file_path'] ?? '');
        return $path !== '' && is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * A URL the renderer can fetch without credentials, valid for $ttl seconds.
     * Fails closed when MEDIA_SIGNING_KEY is unset: an unsigned URL would
     * never validate, and the render would fail later with a vaguer error.
     */
    public function signedUrl(array $media, int $ttl = self::DEFAULT_TTL_SECS): string
    {
        if (self::key() === '') {
            throw VideoProviderException::notConfigured('MEDIA_SIGNING_KEY is not set — the renderer cannot be given voice-over/still URLs.');
        }
        $exp  = time() + $ttl;
        $uuid = (string) $media['uuid'];
        $ext  = self::EXT[(string) $media['mime']] ?? 'bin';

        return self::baseUrl() . '/api/v1/public/video-media/' . $uuid . '.' . $ext . '?exp=' . $exp . '&sig=' . self::signature($uuid, $exp);
    }

    public static function signature(string $uuid, int $exp): string
    {
        return hash_hmac('sha256', 'video-media|' . $uuid . '|' . $exp, self::key());
    }

    public static function verify(string $uuid, int $exp, string $sig): bool
    {
        if (self::key() === '' || $exp < time()) {
            return false;
        }
        return hash_equals(self::signature($uuid, $exp), $sig);
    }

    /** Delete an item's local voice-overs and stills once YouTube has the video (rows go too). */
    public function purgeForItem(int $itemId, array $keepIds = []): int
    {
        $rows = $this->db->table('reach_video_media')->where('item_id', $itemId)->get()->getResultArray();
        $n    = 0;
        foreach ($rows as $row) {
            if (in_array((int) $row['id'], $keepIds, true)) {
                continue;
            }
            if (is_file((string) $row['file_path'])) {
                @unlink((string) $row['file_path']);
            }
            $this->db->table('reach_video_media')->where('id', (int) $row['id'])->delete();
            $n++;
        }
        return $n;
    }

    public function storagePath(): string
    {
        $configured = trim((string) (env('VIDEO_MEDIA_STORAGE_PATH') ?? ''));
        return $configured !== '' ? rtrim($configured, '/') . '/' : rtrim(WRITEPATH, '/') . '/uploads/video-media/';
    }

    private static function key(): string
    {
        return (string) (env('MEDIA_SIGNING_KEY') ?? '');
    }

    private static function baseUrl(): string
    {
        return rtrim((string) (env('REACH_PUBLIC_BASE_URL') ?: env('REACH_APP_URL') ?: 'https://reach.aicountly.org'), '/');
    }

    private static function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

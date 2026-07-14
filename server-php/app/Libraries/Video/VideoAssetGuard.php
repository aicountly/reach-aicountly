<?php

declare(strict_types=1);

namespace App\Libraries\Video;

/**
 * Validates video asset uploads before storage.
 *
 * Guards:
 *   - MIME allowlist (finfo-based, not extension-only)
 *   - File extension cross-check
 *   - Maximum upload size
 *   - No executable MIME types
 *   - Tenant-isolated storage key format
 */
class VideoAssetGuard
{
    private const ALLOWED_MIME_TYPES = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/vtt',
        'text/plain',
        'application/json',
        'application/x-subrip',
    ];

    private const MIME_TO_EXTENSIONS = [
        'video/mp4'              => ['mp4'],
        'video/webm'             => ['webm'],
        'video/quicktime'        => ['mov', 'qt'],
        'image/jpeg'             => ['jpg', 'jpeg'],
        'image/png'              => ['png'],
        'image/webp'             => ['webp'],
        'text/vtt'               => ['vtt'],
        'text/plain'             => ['txt', 'vtt', 'srt'],
        'application/json'       => ['json'],
        'application/x-subrip'  => ['srt'],
    ];

    private const MAX_BYTES_DEFAULT = 524288000;

    public function __construct(
        private readonly int $maxBytes = self::MAX_BYTES_DEFAULT,
    ) {}

    /**
     * Validate an upload from a $_FILES-style array.
     *
     * @param array{tmp_name?: string, name?: string, size?: int, type?: string} $file
     * @return array{valid: bool, error?: string, mime?: string, extension?: string}
     */
    public function validate(array $file): array
    {
        $tmpName   = $file['tmp_name'] ?? '';
        $origName  = $file['name'] ?? '';
        $fileSize  = (int) ($file['size'] ?? 0);

        if ($tmpName === '' || ! file_exists($tmpName)) {
            return ['valid' => false, 'error' => 'No uploaded file found'];
        }

        if ($fileSize > $this->maxBytes) {
            return ['valid' => false, 'error' => "File size {$fileSize} exceeds maximum {$this->maxBytes} bytes"];
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpName);
        finfo_close($finfo);

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ['valid' => false, 'error' => "MIME type '{$mimeType}' is not allowed"];
        }

        $extension = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed   = self::MIME_TO_EXTENSIONS[$mimeType] ?? [];
        if ($extension !== '' && ! in_array($extension, $allowed, true)) {
            return ['valid' => false, 'error' => "Extension '{$extension}' does not match MIME type '{$mimeType}'"];
        }

        if ($extension === '') {
            $extension = $allowed[0] ?? 'bin';
        }

        return ['valid' => true, 'mime' => $mimeType, 'extension' => $extension];
    }

    /**
     * Build a tenant-isolated, traversal-safe storage key.
     */
    public function buildStorageKey(int $tenantId, string $uuid, string $extension): string
    {
        $safeExt = preg_replace('/[^a-z0-9]/', '', strtolower($extension));
        $safeUuid = preg_replace('/[^a-f0-9\-]/', '', strtolower($uuid));
        return "video/{$tenantId}/{$safeUuid}.{$safeExt}";
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Media;

/**
 * Re-encodes cover images as WebP under the public-site hero fetcher's hard
 * 2 MB cap (HeroImageFetcher on aicountly.com refuses larger bodies).
 * gpt-image-1 PNGs routinely exceed that cap, so this is not optional polish.
 */
class ImagePostProcessor
{
    public const MAX_BYTES = 1900000; // < 2 MiB with headroom

    /**
     * @return array{bytes:string, mime:string, width:int, height:int}
     */
    public function toWebUnderCap(string $binary): array
    {
        if (! extension_loaded('gd')) {
            if (strlen($binary) > self::MAX_BYTES) {
                throw new \RuntimeException('image_too_large_and_gd_unavailable');
            }
            $info = getimagesizefromstring($binary);
            if ($info === false) {
                throw new \RuntimeException('not_an_image');
            }

            return [
                'bytes'  => $binary,
                'mime'   => (string) ($info['mime'] ?? 'application/octet-stream'),
                'width'  => (int) $info[0],
                'height' => (int) $info[1],
            ];
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new \RuntimeException('not_an_image');
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $width  = imagesx($image);
        $height = imagesy($image);

        foreach ([85, 75, 60, 45] as $quality) {
            ob_start();
            $ok    = imagewebp($image, null, $quality);
            $bytes = (string) ob_get_clean();
            if ($ok && $bytes !== '' && strlen($bytes) <= self::MAX_BYTES) {
                imagedestroy($image);

                return ['bytes' => $bytes, 'mime' => 'image/webp', 'width' => $width, 'height' => $height];
            }
        }

        // Still too large: halve dimensions once and retry at moderate quality.
        $resized = imagescale($image, max(1, intdiv($width, 2)));
        imagedestroy($image);
        if ($resized === false) {
            throw new \RuntimeException('image_resize_failed');
        }

        ob_start();
        $ok    = imagewebp($resized, null, 70);
        $bytes = (string) ob_get_clean();
        $w     = imagesx($resized);
        $h     = imagesy($resized);
        imagedestroy($resized);

        if (! $ok || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('image_exceeds_size_cap_after_recompression');
        }

        return ['bytes' => $bytes, 'mime' => 'image/webp', 'width' => $w, 'height' => $h];
    }
}

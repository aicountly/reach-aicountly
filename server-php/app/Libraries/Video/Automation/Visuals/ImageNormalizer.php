<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Visuals;

/**
 * Turns provider images into what the renderer and YouTube accept: JPEG
 * (Shotstack fetches it; YouTube thumbnails must be JPEG/PNG ≤ 2 MB). With
 * GD it re-encodes and, when asked, cover-crops to an exact size; without GD
 * a PNG/JPEG under the cap passes through unchanged.
 */
final class ImageNormalizer
{
    public const THUMBNAIL_MAX_BYTES = 2_000_000;

    /** @return array{bytes: string, mime: string, width: int, height: int} */
    public static function toJpeg(string $bytes, ?int $width = null, ?int $height = null, int $maxBytes = 4_000_000): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new \RuntimeException('not_an_image');
        }
        if (! function_exists('imagecreatefromstring')) {
            if (strlen($bytes) > $maxBytes || ! in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
                throw new \RuntimeException('image_needs_gd');
            }
            return ['bytes' => $bytes, 'mime' => (string) $info['mime'], 'width' => (int) $info[0], 'height' => (int) $info[1]];
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new \RuntimeException('not_an_image');
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $tw = $width ?? $sw;
        $th = $height ?? $sh;

        // Cover-crop: scale to fill the target, centre the crop.
        $scale = max($tw / $sw, $th / $sh);
        $cw    = (int) round($tw / $scale);
        $ch    = (int) round($th / $scale);
        $cx    = (int) max(0, ($sw - $cw) / 2);
        $cy    = (int) max(0, ($sh - $ch) / 2);

        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);
        imagedestroy($src);

        foreach ([88, 80, 70, 60, 50] as $q) {
            ob_start();
            imagejpeg($dst, null, $q);
            $out = (string) ob_get_clean();
            if (strlen($out) <= $maxBytes) {
                imagedestroy($dst);
                return ['bytes' => $out, 'mime' => 'image/jpeg', 'width' => $tw, 'height' => $th];
            }
        }
        imagedestroy($dst);
        throw new \RuntimeException('image_exceeds_size_cap');
    }
}

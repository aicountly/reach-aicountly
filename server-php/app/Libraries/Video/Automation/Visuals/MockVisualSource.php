<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Visuals;

use App\Libraries\Video\Automation\VideoProviders;

/** Development/test stills and screenshots: a flat navy PNG. Refuses in production. */
final class MockVisualSource implements ScreenshotSource, StillImageSource
{
    public function __construct()
    {
        VideoProviders::refuseMockInProduction('visual source');
    }

    public function capture(string $url, int $width, int $height): string
    {
        return self::png(min($width, 320), min($height, 180));
    }

    public function generate(string $prompt, string $orientation): string
    {
        return $orientation === 'portrait' ? self::png(180, 320) : self::png(320, 180);
    }

    public static function png(int $w, int $h): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor($w, $h);
            imagefill($im, 0, 0, imagecolorallocate($im, 7, 26, 51));
            ob_start();
            imagepng($im);
            imagedestroy($im);
            return (string) ob_get_clean();
        }
        // 1x1 navy PNG
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGPgUjIBAABvADPfY0pGAAAAAElFTkSuQmCC');
    }
}

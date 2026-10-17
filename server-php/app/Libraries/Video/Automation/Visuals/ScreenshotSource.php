<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Visuals;

interface ScreenshotSource
{
    /**
     * Capture $url at the given viewport and return image bytes (PNG/JPEG).
     *
     * @throws \App\Libraries\Video\Automation\VideoProviderException
     */
    public function capture(string $url, int $width, int $height): string;
}

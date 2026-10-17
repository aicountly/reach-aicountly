<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Visuals;

interface StillImageSource
{
    /**
     * Generate a still for $prompt; $orientation is landscape|portrait.
     * Returns image bytes.
     *
     * @throws \App\Libraries\Video\Automation\VideoProviderException
     */
    public function generate(string $prompt, string $orientation): string;
}

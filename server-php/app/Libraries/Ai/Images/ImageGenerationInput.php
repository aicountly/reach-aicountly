<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Images;

/**
 * Value object representing a single image generation request.
 *
 * No secrets are stored here. The requestId is used for correlation only.
 */
final class ImageGenerationInput
{
    public function __construct(
        public readonly string  $prompt,
        public readonly string  $modelKey = '',
        public readonly int     $width = 1536,
        public readonly int     $height = 1024,
        public readonly string  $format = 'webp',
        public readonly int     $count = 1,
        public readonly int     $timeoutSeconds = 60,
        public readonly ?string $requestId = null,
        public readonly array   $extraParams = [],
    ) {
    }
}

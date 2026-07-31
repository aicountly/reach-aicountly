<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Images;

/**
 * Value object representing a successful image generation response.
 *
 * Each image entry contains optional base64 data, optional URL, and MIME type.
 */
final class ImageGenerationResult
{
    /**
     * @param list<array{b64: string|null, url: string|null, mime: string}> $images
     */
    public function __construct(
        public readonly array   $images,
        public readonly string  $providerKey,
        public readonly string  $modelKey,
        public readonly ?string $providerResponseId,
        public readonly int     $durationMs,
        public readonly int     $imageCount,
    ) {
    }
}

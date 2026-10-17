<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Render;

/**
 * A render's state as the provider reports it. `status` is normalised to
 * queued | rendering | done | failed; `providerStatus` keeps the raw word.
 */
final class RenderResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $providerStatus,
        public readonly ?string $url = null,
        public readonly ?string $posterUrl = null,
        public readonly ?string $thumbnailUrl = null,
        public readonly ?string $error = null,
        public readonly ?float $durationSecs = null,
    ) {
    }

    public function done(): bool
    {
        return $this->status === 'done';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}

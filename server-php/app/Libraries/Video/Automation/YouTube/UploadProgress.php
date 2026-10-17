<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\YouTube;

/** Where a resumable upload stands after one step. */
final class UploadProgress
{
    /** @param array<string, mixed> $video the videos resource once the upload completed */
    public function __construct(
        public readonly int $offset,
        public readonly int $total,
        public readonly bool $complete,
        public readonly ?string $videoId = null,
        public readonly array $video = [],
    ) {
    }
}

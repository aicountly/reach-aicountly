<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

final class YouTubeVideoStatus
{
    public function __construct(
        public readonly string             $remoteVideoId,
        public readonly string             $processingStatus,
        public readonly string             $uploadStatus,
        public readonly ?string            $failureReason,
        public readonly ?string            $watchUrl,
        public readonly \DateTimeImmutable $retrievedAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

final class YouTubeUploadReceipt
{
    public function __construct(
        public readonly string $remoteVideoId,
        public readonly string $uploadStatus,
        public readonly ?string $uploadUrl,
        public readonly string $idempotencyKey,
        public readonly array  $receiptRaw,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\YouTube;

interface YouTubeClient
{
    /**
     * Open a resumable upload session for a video of $bytes bytes and return
     * its session URI.
     *
     * @param array<string, mixed> $resource videos resource (snippet + status)
     */
    public function startUpload(array $resource, int $bytes, string $mime = 'video/mp4'): string;

    /**
     * Stream the next chunks of $sourceUrl into the session until the upload
     * completes or $deadline (unix time) passes.
     */
    public function continueUpload(string $sessionUrl, string $sourceUrl, int $total, int $offset, int $deadline): UploadProgress;

    /** Size in bytes of the file at $sourceUrl (HEAD). */
    public function sourceSize(string $sourceUrl): int;

    public function setThumbnail(string $videoId, string $bytes, string $mime): void;

    public function insertCaption(string $videoId, string $language, string $name, string $srt): string;

    /** @return array{id: string, title: string} */
    public function channel(): array;

    public function name(): string;
}

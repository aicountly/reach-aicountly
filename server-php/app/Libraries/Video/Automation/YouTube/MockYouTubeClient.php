<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\YouTube;

use App\Libraries\Video\Automation\VideoProviders;

/**
 * Development/test YouTube: "uploads" complete in one step with an id that
 * starts with MOCK, so nothing downstream can mistake it for a real video.
 * Refuses in production.
 */
final class MockYouTubeClient implements YouTubeClient
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function __construct(private readonly string $privacy = 'public')
    {
        VideoProviders::refuseMockInProduction('YouTube publisher');
    }

    public function name(): string
    {
        return 'mock_youtube';
    }

    public function startUpload(array $resource, int $bytes, string $mime = 'video/mp4'): string
    {
        $this->calls[] = ['startUpload', $resource, $bytes];
        return 'mock://youtube/upload/' . bin2hex(random_bytes(6));
    }

    public function continueUpload(string $sessionUrl, string $sourceUrl, int $total, int $offset, int $deadline): UploadProgress
    {
        $this->calls[] = ['continueUpload', $sessionUrl, $sourceUrl];
        $id = 'MOCK' . strtoupper(bin2hex(random_bytes(4)));
        return new UploadProgress($total, $total, true, $id, ['id' => $id, 'status' => ['privacyStatus' => $this->privacy, 'uploadStatus' => 'uploaded']]);
    }

    public function sourceSize(string $sourceUrl): int
    {
        return 1_048_576;
    }

    public function setThumbnail(string $videoId, string $bytes, string $mime): void
    {
        $this->calls[] = ['setThumbnail', $videoId, strlen($bytes), $mime];
    }

    public function insertCaption(string $videoId, string $language, string $name, string $srt): string
    {
        $this->calls[] = ['insertCaption', $videoId, $language, $srt];
        return 'mock-caption';
    }

    public function channel(): array
    {
        return ['id' => 'UC_MOCK_CHANNEL', 'title' => 'Mock channel'];
    }
}

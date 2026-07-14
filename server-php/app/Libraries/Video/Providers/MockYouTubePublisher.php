<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

/**
 * Deterministic mock YouTube publisher for use in CI and testing.
 *
 * Returns fixed receipts with remote_video_id = "yt-mock-{project_uuid}".
 * No network calls are made.
 */
class MockYouTubePublisher implements YouTubePublisherInterface
{
    private array $uploadedVideos = [];

    public function upload(array $payload): YouTubeUploadReceipt
    {
        $idempotencyKey = $payload['idempotency_key'] ?? '';
        $projectUuid    = $payload['project_uuid'] ?? uniqid('proj-');
        $remoteVideoId  = "yt-mock-{$projectUuid}";

        if ($idempotencyKey !== '' && isset($this->uploadedVideos[$idempotencyKey])) {
            return $this->uploadedVideos[$idempotencyKey];
        }

        $receipt = new YouTubeUploadReceipt(
            remoteVideoId:  $remoteVideoId,
            uploadStatus:   'uploaded',
            uploadUrl:      "https://www.youtube.com/watch?v={$remoteVideoId}",
            idempotencyKey: $idempotencyKey,
            receiptRaw:     [
                'mock'            => true,
                'remote_video_id' => $remoteVideoId,
                'project_uuid'    => $projectUuid,
            ],
        );

        if ($idempotencyKey !== '') {
            $this->uploadedVideos[$idempotencyKey] = $receipt;
        }

        return $receipt;
    }

    public function setMetadata(string $remoteVideoId, array $metadata): bool
    {
        return true;
    }

    public function uploadCaption(string $remoteVideoId, array $caption): string
    {
        $language = $caption['language'] ?? 'en';
        return "yt-mock-caption-{$language}";
    }

    public function setThumbnail(string $remoteVideoId, string $imageUrl): bool
    {
        return true;
    }

    public function getStatus(string $remoteVideoId): YouTubeVideoStatus
    {
        return new YouTubeVideoStatus(
            remoteVideoId:     $remoteVideoId,
            processingStatus:  'succeeded',
            uploadStatus:      'processed',
            failureReason:     null,
            watchUrl:          "https://www.youtube.com/watch?v={$remoteVideoId}",
            retrievedAt:       new \DateTimeImmutable(),
        );
    }

    public function getReceiptNormalized(array $rawReceipt): array
    {
        $safe = $rawReceipt;
        unset($safe['access_token'], $safe['refresh_token'], $safe['client_secret']);
        return $safe;
    }
}

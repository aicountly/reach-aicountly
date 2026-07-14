<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

interface YouTubePublisherInterface
{
    /**
     * Upload a video file to YouTube.
     *
     * @param array{
     *   project_uuid: string,
     *   video_asset_url: string,
     *   idempotency_key: string,
     *   connection_id: int,
     * } $payload
     *
     * @throws YouTubeAuthException
     * @throws YouTubeQuotaException
     * @throws YouTubeRejectionException
     * @throws YouTubeTransientException
     */
    public function upload(array $payload): YouTubeUploadReceipt;

    /**
     * Set video title, description, tags, category, and privacy status.
     *
     * @param array{
     *   title: string,
     *   description?: string,
     *   tags?: string[],
     *   category_id?: string,
     *   privacy_status: string,
     * } $metadata
     *
     * @throws YouTubeRejectionException
     */
    public function setMetadata(string $remoteVideoId, array $metadata): bool;

    /**
     * Upload a caption track.
     * Returns the YouTube caption track ID.
     *
     * @param array{
     *   language: string,
     *   name: string,
     *   content: string,
     *   is_default?: bool,
     * } $caption
     */
    public function uploadCaption(string $remoteVideoId, array $caption): string;

    /**
     * Upload a custom thumbnail (JPEG or PNG, max 2 MB).
     * The imageUrl must already be validated against UrlPolicy before calling.
     */
    public function setThumbnail(string $remoteVideoId, string $imageUrl): bool;

    /**
     * Query processing status of an uploaded video.
     */
    public function getStatus(string $remoteVideoId): YouTubeVideoStatus;

    /**
     * Normalise a raw provider receipt into the canonical verification_payload format.
     * Implementations MUST NOT include OAuth tokens or client secrets.
     */
    public function getReceiptNormalized(array $rawReceipt): array;
}

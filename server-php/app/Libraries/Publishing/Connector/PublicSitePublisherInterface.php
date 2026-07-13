<?php

namespace App\Libraries\Publishing\Connector;

/**
 * Phase 4 — Contract for all public-site publishers.
 *
 * Implementations must never log secrets and must use HMAC-signed requests.
 */
interface PublicSitePublisherInterface
{
    /**
     * Create a draft on the public site.
     *
     * @param array $envelope Full publishing envelope (operation, payload, auth fields)
     * @return array{success: bool, public_content_id: ?int, public_content_uuid: ?string, public_status: ?string, canonical_url: ?string, error_category: ?string, safe_error_message: ?string}
     */
    public function createDraft(array $envelope): array;

    /**
     * Update an existing draft.
     */
    public function updateDraft(int $publicContentId, array $envelope): array;

    /**
     * Publish a draft immediately.
     */
    public function publish(int $publicContentId, array $envelope): array;

    /**
     * Schedule content for future publication.
     */
    public function schedule(int $publicContentId, array $envelope, string $scheduledAt): array;

    /**
     * Unpublish content.
     */
    public function unpublish(int $publicContentId, string $reason): array;

    /**
     * Restore previously unpublished content.
     */
    public function restore(int $publicContentId, array $envelope): array;

    /**
     * Get current status from public site.
     */
    public function getStatus(int $publicContentId): array;

    /**
     * Get verification data.
     */
    public function getVerification(int $publicContentId): array;

    /**
     * Trigger a verification run and return fresh data.
     */
    public function triggerVerification(int $publicContentId): array;

    /**
     * Health check — does not require HMAC signing.
     *
     * @return bool True if the public site API is reachable and healthy.
     */
    public function healthCheck(): bool;
}

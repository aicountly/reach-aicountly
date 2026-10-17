<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

/**
 * socialapi.ai publisher — FAIL-CLOSED PLACEHOLDER.
 *
 * Selected by ChannelProviderFactory when the `social.socialapi.api_key`
 * secret is set (Settings → AI & Automation → Social, or SOCIALAPI_API_KEY).
 * socialapi.ai's API reference was not available when this was written, so
 * no request shapes are guessed here: every publishing call throws and the
 * post is recorded as failed. Nothing is ever reported as posted.
 *
 * To make it real, replace the bodies below (this file only) once the docs
 * are in hand. What is needed is listed in
 * docs/operations/CAMPAIGN_READINESS.md § "socialapi.ai — what the adapter needs":
 *   - list connected accounts/pages per network (to map platform → account id)
 *   - create a post per network (text, link, image/video upload or URL)
 *   - schedule a post (publish_at) and cancel it
 *   - post status (and/or a signed status webhook: header + signing scheme)
 *   - delete/withdraw a post
 *   - per-post analytics (impressions, clicks, engagement)
 *   - error model: rate-limit signalling (429 + Retry-After?) vs permanent rejects
 */
class SocialApiAiPublisher implements SocialPublisherInterface
{
    public const PENDING_MESSAGE = 'socialapi.ai adapter pending: API docs required. Nothing was posted.';

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $baseUrl = null,
    ) {}

    public function publish(ChannelMessage $message, string $platform): ProviderReceipt
    {
        throw new ProviderNotConfiguredException(self::PENDING_MESSAGE);
    }

    public function getStatus(string $providerPostId): ProviderStatus
    {
        throw new ProviderNotConfiguredException(self::PENDING_MESSAGE);
    }

    public function withdraw(string $providerPostId): bool
    {
        throw new ProviderNotConfiguredException(self::PENDING_MESSAGE);
    }

    public function getCapabilities(): array
    {
        return [
            'platforms'   => [],
            'pending'     => true,
            'reason'      => self::PENDING_MESSAGE,
            'base_url'    => $this->baseUrl,
            'credentials' => $this->apiKey !== '' ? 'set' : 'missing',
        ];
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function verifyCallback(array $headers, string $rawBody): bool
    {
        return false;
    }

    public function providerName(): string
    {
        return 'socialapi';
    }
}

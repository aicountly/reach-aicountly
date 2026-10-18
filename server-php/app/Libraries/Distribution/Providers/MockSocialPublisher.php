<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

class MockSocialPublisher implements SocialPublisherInterface
{
    public function publish(ChannelMessage $message, string $platform): ProviderReceipt
    {
        $this->assertNotConfiguredForProduction();
        $scenario = $this->scenario($message->idempotencyKey);
        if ($scenario === 'rate_limit') {
            throw new \RuntimeException('MOCK: rate limit exceeded', 429);
        }
        if ($scenario === 'transient_fail') {
            throw new \RuntimeException('MOCK: transient failure', 503);
        }
        if ($scenario === 'perm_fail') {
            throw new \RuntimeException('MOCK: permanent rejection', 400);
        }
        return new ProviderReceipt(
            providerMessageId: 'mock-post-' . substr(md5($message->idempotencyKey), 0, 12),
            status:            'accepted',
            acceptedAt:        new \DateTimeImmutable(),
            remoteUrl:         'https://mock-social.example.com/posts/mock-' . substr(md5($message->idempotencyKey), 0, 8),
        );
    }

    public function getStatus(string $providerPostId): ProviderStatus
    {
        return new ProviderStatus(providerMessageId: $providerPostId, normalisedStatus: 'posted');
    }

    public function withdraw(string $providerPostId): bool
    {
        return true;
    }

    public function getCapabilities(): array
    {
        return ['platforms' => ['linkedin', 'twitter', 'facebook', 'instagram'], 'char_limit' => 3000, 'media_types' => ['image', 'video']];
    }

    public function isEnabled(): bool
    {
        return !ProviderGuard::isProduction(); // dev/test only
    }

    public function verifyCallback(array $headers, string $rawBody): bool
    {
        return true;
    }

    public function providerName(): string
    {
        return 'mock_social';
    }

    private function scenario(string $key): string
    {
        if (str_contains($key, 'rate_limit'))    return 'rate_limit';
        if (str_contains($key, 'transient_fail')) return 'transient_fail';
        if (str_contains($key, 'perm_fail'))     return 'perm_fail';
        return 'success';
    }

    /**
     * The mock used to fall through here in production and hand back fake
     * `mock-post-…` ids, which marked posts `posted` that never left Reach.
     * It now refuses outright.
     */
    private function assertNotConfiguredForProduction(): void
    {
        ProviderGuard::refuseMockInProduction(
            'Social',
            'no real social publisher is available (set social.socialapi.api_key — Settings → AI & Automation → Social — to publish through social-api.ai)'
        );
    }
}

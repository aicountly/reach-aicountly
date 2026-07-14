<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

/**
 * Phase 6 CP7 — Production render adapter skeleton.
 *
 * This adapter is DISABLED by default (VIDEO_RENDER_PROVIDER != 'production').
 *
 * Integration guide:
 * ─────────────────
 * 1. Set VIDEO_RENDER_PROVIDER=production in your .env (never in CI).
 * 2. Configure VIDEO_RENDER_API_KEY, VIDEO_RENDER_API_ENDPOINT, and
 *    VIDEO_RENDER_HMAC_KEY in your production .env.
 * 3. Implement the abstract methods below using your chosen render provider
 *    (e.g. Creatomate, Shotstack, RunwayML, etc.).
 * 4. Register the adapter in VideoProviderFactory::renderProvider().
 * 5. Test using the callback simulator: POST v1/video/provider/render-callback
 *    with a valid HMAC-SHA256 signature.
 *
 * Security requirements (ALL must be satisfied before enabling):
 * ─────────────────────────────────────────────────────────────
 * - HMAC-SHA256 callback verification (VIDEO_RENDER_HMAC_KEY required).
 * - Timestamp tolerance window ≤ 300 seconds.
 * - Provider event ID deduplication (reach_video_provider_events).
 * - No outbound URLs outside UrlPolicy SSRF allowlist.
 * - API key masked in all logs and audit records.
 *
 * @see \App\Libraries\Video\Providers\RenderProviderInterface for the full contract.
 * @see \App\Libraries\Video\VideoCallbackAuthenticator for HMAC verification.
 * @see \App\Libraries\Video\Providers\MockRenderProvider for the CI default.
 */
class ProductionRenderAdapter implements RenderProviderInterface
{
    private const PROVIDER_NAME = 'production';

    public function __construct()
    {
        if ((string) env('VIDEO_RENDER_PROVIDER', 'mock') !== 'production') {
            throw new \LogicException(
                'ProductionRenderAdapter requires VIDEO_RENDER_PROVIDER=production in .env. '
                . 'The mock provider is the default for CI.'
            );
        }
        $this->validateConfig();
    }

    private function validateConfig(): void
    {
        $required = ['VIDEO_RENDER_API_KEY', 'VIDEO_RENDER_API_ENDPOINT', 'VIDEO_RENDER_HMAC_KEY'];
        foreach ($required as $key) {
            if (empty(env($key))) {
                throw new \LogicException(
                    "ProductionRenderAdapter requires {$key} to be configured in .env"
                );
            }
        }
    }

    public function providerName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function queue(array $job): RenderReceipt
    {
        // TODO: Implement HTTP call to your chosen render provider.
        // Example: POST to env('VIDEO_RENDER_API_ENDPOINT') with HMAC-signed headers.
        // Return a RenderReceipt with the provider's job ID.
        throw new \LogicException(
            'ProductionRenderAdapter::queue() is not yet implemented. '
            . 'Complete this stub before enabling production rendering.'
        );
    }

    public function status(string $providerJobId): RenderStatus
    {
        // TODO: Implement GET to check render job status from provider.
        throw new \LogicException('ProductionRenderAdapter::status() is not yet implemented.');
    }

    public function cancel(string $providerJobId): bool
    {
        // TODO: Implement DELETE/cancel to provider.
        throw new \LogicException('ProductionRenderAdapter::cancel() is not yet implemented.');
    }

    public function supportsCallback(): bool
    {
        return true;
    }

    public function normalizeCallbackPayload(array $raw): array
    {
        // TODO: Map provider-specific callback payload to canonical format.
        return $raw;
    }

    public function getCapabilities(): array
    {
        return [
            'supports_callback'      => true,
            'supports_cancellation'  => false,
            'max_output_size_bytes'  => null,
        ];
    }
}

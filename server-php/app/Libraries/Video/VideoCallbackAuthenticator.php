<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Models\Video\VideoProviderEventModel;

/**
 * Authenticates inbound provider callbacks.
 *
 * Verification steps (all must pass):
 *   1. Timestamp tolerance:  |now - X-Timestamp| <= tolerance window
 *   2. HMAC verification:    hash_equals(computed, header value)
 *   3. Replay guard:         provider_event_id not already in reach_video_provider_events
 *   4. Dedup insert:         record event to prevent future replay
 */
class VideoCallbackAuthenticator
{
    private const DEFAULT_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly VideoProviderEventModel $eventModel,
        private readonly int                     $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ) {}

    /**
     * Verify a provider callback request.
     *
     * @param string $rawBody         Raw request body bytes
     * @param string $hmacKey         Secret key for this provider
     * @param string $signatureHeader Value of X-Signature header (e.g. "sha256=abc123")
     * @param int    $timestamp       Value of X-Timestamp header
     * @param string $providerEventId Value of X-Provider-Event-Id header
     * @param string $provider        Provider name ('mock_render', 'youtube', etc.)
     *
     * @return array{ok: bool, reason?: string}
     */
    public function verify(
        string $rawBody,
        string $hmacKey,
        string $signatureHeader,
        int    $timestamp,
        string $providerEventId,
        string $provider,
    ): array {
        $now = time();
        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            return ['ok' => false, 'reason' => 'timestamp_out_of_window'];
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return ['ok' => false, 'reason' => 'invalid_signature_format'];
        }

        $headerHex   = substr($signatureHeader, 7);
        $computedHex = hash_hmac('sha256', $rawBody, $hmacKey);

        if (! hash_equals($computedHex, $headerHex)) {
            return ['ok' => false, 'reason' => 'invalid_signature'];
        }

        if ($this->eventModel->isDuplicate($provider, $providerEventId)) {
            return ['ok' => false, 'reason' => 'replay_detected'];
        }

        $payloadHash = hash('sha256', $rawBody);
        $this->eventModel->record($provider, $providerEventId, '', $payloadHash);

        return ['ok' => true];
    }
}

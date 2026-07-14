<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Models\Distribution\CampaignProviderEventModel;

/**
 * Authenticates inbound distribution provider callbacks.
 *
 * Extended from the Phase 6 VideoCallbackAuthenticator pattern.
 * HMAC-SHA256 + timestamp tolerance + replay deduplication.
 */
class DistributionCallbackAuthenticator
{
    private const MAX_SKEW_SECONDS  = 300;
    private const SIGNATURE_HEADER  = 'X-Distribution-Signature';
    private const TIMESTAMP_HEADER  = 'X-Distribution-Timestamp';

    public function __construct(
        private readonly CampaignProviderEventModel $eventModel,
    ) {}

    /**
     * @param array  $headers       Request headers (lowercase keys)
     * @param string $rawBody       Raw request body
     * @param string $connectionSecret  HMAC secret for this connection
     * @param string $provider         Provider slug for dedup check
     * @param ?int   $connectionId     Connection ID for dedup
     * @param ?string $providerEventId Event ID for replay check
     */
    public function verify(
        array   $headers,
        string  $rawBody,
        string  $connectionSecret,
        string  $provider,
        ?int    $connectionId,
        ?string $providerEventId = null,
    ): bool {
        // 1. Signature check
        $sigHeader = $headers[strtolower(self::SIGNATURE_HEADER)] ?? $headers['x-distribution-signature'] ?? '';
        if (!str_starts_with((string) $sigHeader, 'sha256=')) {
            return false;
        }
        $received = substr((string) $sigHeader, 7);
        $expected = hash_hmac('sha256', $rawBody, $connectionSecret);
        if (!hash_equals($expected, $received)) {
            return false;
        }

        // 2. Timestamp tolerance
        $tsHeader = $headers[strtolower(self::TIMESTAMP_HEADER)] ?? $headers['x-distribution-timestamp'] ?? '';
        if ($tsHeader !== '') {
            $ts = (int) $tsHeader;
            if (abs(time() - $ts) > self::MAX_SKEW_SECONDS) {
                return false;
            }
        }

        // 3. Replay check
        if ($providerEventId !== null && $providerEventId !== '') {
            if ($this->eventModel->isDuplicate($provider, $connectionId, $providerEventId)) {
                return false;
            }
        }

        return true;
    }

    public static function computeSignature(string $rawBody, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    }
}

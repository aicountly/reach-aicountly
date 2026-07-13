<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Round-trip tests that simulate the full Reach → aicountly-com signing and verification flow.
 *
 * @group publishing
 */
class HmacRoundTripTest extends CIUnitTestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new HmacSigner();
    }

    /**
     * Simulates what aicountly-com server would do to verify the request.
     */
    private function serverVerify(array $headers, string $body, string $signingKey): bool
    {
        // 1. Verify body checksum
        $expectedBodyHash = $this->signer->bodyHash($body);
        if (!hash_equals($expectedBodyHash, $headers['X-Reach-Content-SHA256'])) {
            return false;
        }

        // 2. Rebuild canonical string
        $canonical = $this->signer->buildCanonicalString(
            'POST',
            '/api/internal/reach/v1/content/drafts',
            (int) $headers['X-Reach-Timestamp'],
            $headers['X-Reach-Nonce'],
            $headers['X-Reach-Content-SHA256'],
            $headers['X-Idempotency-Key'],
            (int) $headers['X-Reach-API-Version']
        );

        // 3. Compute expected signature
        $expected = $this->signer->sign($canonical, $signingKey);

        // 4. Timing-safe compare
        return $this->signer->verify($headers['X-Reach-Signature'], $expected);
    }

    public function testFullRoundTripPassesVerification(): void
    {
        $body       = json_encode(['title' => 'Test Article', 'content' => '<p>Body</p>']);
        $signingKey = 'round-trip-test-signing-key-32ch!!';

        $headers = $this->signer->buildAuthHeaders(
            'POST',
            '/api/internal/reach/v1/content/drafts',
            $body,
            'ikey-' . uniqid(),
            'req-' . uniqid(),
            'service-token',
            $signingKey,
            'reach-v1',
            1
        );

        $this->assertTrue($this->serverVerify($headers, $body, $signingKey));
    }

    public function testTamperedBodyFailsVerification(): void
    {
        $body       = json_encode(['title' => 'Original Title']);
        $signingKey = 'test-key';

        $headers     = $this->signer->buildAuthHeaders('POST', '/api/internal/reach/v1/content/drafts', $body, 'k', 'r', 'bt', $signingKey, 'kid', 1);
        $tamperedBody = json_encode(['title' => 'Hacker Title']);

        $result = $this->serverVerify($headers, $tamperedBody, $signingKey);
        $this->assertFalse($result);
    }

    public function testWrongSigningKeyFailsVerification(): void
    {
        $body          = '{"title":"Test"}';
        $correctKey    = 'correct-signing-key';
        $incorrectKey  = 'wrong-signing-key-xxx';

        $headers = $this->signer->buildAuthHeaders('POST', '/path', $body, 'k', 'r', 'bt', $correctKey, 'kid', 1);

        // Server tries to verify with the wrong key
        $canonical = $this->signer->buildCanonicalString(
            'POST', '/path',
            (int) $headers['X-Reach-Timestamp'],
            $headers['X-Reach-Nonce'],
            $headers['X-Reach-Content-SHA256'],
            $headers['X-Idempotency-Key'],
            (int) $headers['X-Reach-API-Version']
        );

        $wrongExpected = $this->signer->sign($canonical, $incorrectKey);
        $this->assertFalse($this->signer->verify($headers['X-Reach-Signature'], $wrongExpected));
    }

    public function testMultipleRoundTripsAreIndependent(): void
    {
        $signingKey = 'multi-test-key';

        for ($i = 0; $i < 3; $i++) {
            $body    = json_encode(['article' => $i]);
            $headers = $this->signer->buildAuthHeaders(
                'POST', '/api/internal/reach/v1/content/drafts', $body,
                "ikey-{$i}", "req-{$i}", 'bt', $signingKey, 'kid', 1
            );
            $this->assertTrue($this->serverVerify($headers, $body, $signingKey), "Round-trip {$i} failed");
        }
    }
}

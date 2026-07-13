<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for HMAC signing round-trip verification.
 *
 * These verify the signing contract between Reach (signer) and aicountly-com (verifier)
 * without making real HTTP calls.
 *
 * @group publishing
 */
class HmacAuthIntegrationTest extends CIUnitTestCase
{
    private HmacSigner $signer;
    private string $signingKey;
    private string $bearerToken;
    private string $keyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer      = new HmacSigner();
        $this->signingKey  = 'integration-test-signing-key-long-enough';
        $this->bearerToken = 'integration-test-bearer-token';
        $this->keyId       = 'test-key-v1';
    }

    public function testSignedHeadersPassSelfVerification(): void
    {
        $body    = json_encode(['title' => 'Test Article', 'content' => '<p>Body</p>']);
        $headers = $this->signer->buildAuthHeaders(
            'POST',
            '/api/internal/reach/v1/content/drafts',
            $body,
            'ikey-' . uniqid(),
            'req-' . uniqid(),
            $this->bearerToken,
            $this->signingKey,
            $this->keyId,
            1
        );

        // Re-compute canonical string the same way the server would
        $timestamp = (int) $headers['X-Reach-Timestamp'];
        $nonce     = $headers['X-Reach-Nonce'];
        $bodyHash  = $this->signer->bodyHash($body);
        $ikey      = $headers['X-Idempotency-Key'];
        $version   = (int) $headers['X-Reach-API-Version'];

        $canonical = $this->signer->buildCanonicalString(
            'POST',
            '/api/internal/reach/v1/content/drafts',
            $timestamp,
            $nonce,
            $bodyHash,
            $ikey,
            $version
        );

        $expected = $this->signer->sign($canonical, $this->signingKey);
        $this->assertTrue($this->signer->verify($headers['X-Reach-Signature'], $expected));
    }

    public function testDifferentMethodsProduceDifferentSignatures(): void
    {
        $body = '{"test":"value"}';
        $ts   = time();
        $headers1 = $this->signer->buildAuthHeaders(
            'POST', '/api/internal/reach/v1/content/drafts', $body,
            'k1', 'r1', $this->bearerToken, $this->signingKey, $this->keyId, 1
        );
        $headers2 = $this->signer->buildAuthHeaders(
            'PUT', '/api/internal/reach/v1/content/drafts', $body,
            'k2', 'r2', $this->bearerToken, $this->signingKey, $this->keyId, 1
        );

        $this->assertNotSame($headers1['X-Reach-Signature'], $headers2['X-Reach-Signature']);
    }

    public function testTimestampIsReasonablyRecent(): void
    {
        $headers = $this->signer->buildAuthHeaders(
            'POST', '/api/internal/reach/v1/test', '{}',
            'k', 'r', $this->bearerToken, $this->signingKey, $this->keyId, 1
        );

        $timestamp = (int) $headers['X-Reach-Timestamp'];
        $now       = time();

        $this->assertGreaterThanOrEqual($now - 5, $timestamp);
        $this->assertLessThanOrEqual($now + 5, $timestamp);
    }

    public function testNonceIsUniqueAcrossCalls(): void
    {
        $body = '{}';
        $h1 = $this->signer->buildAuthHeaders('POST', '/path', $body, 'k1', 'r1', 'bt', $this->signingKey, $this->keyId, 1);
        $h2 = $this->signer->buildAuthHeaders('POST', '/path', $body, 'k2', 'r2', 'bt', $this->signingKey, $this->keyId, 1);

        $this->assertNotSame($h1['X-Reach-Nonce'], $h2['X-Reach-Nonce']);
    }

    public function testAuthorizationHeaderFormatIsBearer(): void
    {
        $headers = $this->signer->buildAuthHeaders(
            'POST', '/path', '{}', 'k', 'r',
            'my-token', $this->signingKey, $this->keyId, 1
        );

        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
        $this->assertSame('Bearer my-token', $headers['Authorization']);
    }

    public function testBodyHashMatchesSha256(): void
    {
        $body = '{"publish": true}';
        $headers = $this->signer->buildAuthHeaders(
            'POST', '/path', $body, 'k', 'r',
            'bt', $this->signingKey, $this->keyId, 1
        );

        $this->assertSame(hash('sha256', $body), $headers['X-Reach-Content-SHA256']);
    }

    public function testEmptyBodyHashIsValidSha256(): void
    {
        $headers = $this->signer->buildAuthHeaders(
            'GET', '/path', '', 'k', 'r',
            'bt', $this->signingKey, $this->keyId, 1
        );

        $this->assertSame(64, strlen($headers['X-Reach-Content-SHA256']));
        $this->assertSame(hash('sha256', ''), $headers['X-Reach-Content-SHA256']);
    }

    public function testSigningKeyNotLeakedInHeaders(): void
    {
        $headers = $this->signer->buildAuthHeaders(
            'POST', '/path', '{}', 'k', 'r',
            $this->bearerToken, $this->signingKey, $this->keyId, 1
        );

        $allHeaderValues = implode('|', $headers);
        $this->assertStringNotContainsString($this->signingKey, $allHeaderValues);
    }
}

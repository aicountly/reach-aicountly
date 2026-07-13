<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Contract tests verifying the HMAC signing protocol matches the API contract specification.
 *
 * @group publishing
 */
class HmacSigningContractTest extends CIUnitTestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new HmacSigner();
    }

    public function testCanonicalStringFormatIsCorrect(): void
    {
        $canonical = $this->signer->buildCanonicalString(
            'POST',
            '/api/internal/reach/v1/content/drafts',
            1752393600,
            'nonce-uuid',
            'body-hash',
            'idempotency-key',
            1
        );

        $parts = explode("\n", $canonical);
        $this->assertCount(7, $parts, 'Canonical string must have exactly 7 newline-separated parts');
        $this->assertSame('POST', $parts[0]);
        $this->assertSame('/api/internal/reach/v1/content/drafts', $parts[1]);
        $this->assertSame('1752393600', $parts[2]);
        $this->assertSame('nonce-uuid', $parts[3]);
        $this->assertSame('body-hash', $parts[4]);
        $this->assertSame('idempotency-key', $parts[5]);
        $this->assertSame('1', $parts[6]);
    }

    public function testRequiredAuthHeadersAllPresent(): void
    {
        $headers = $this->signer->buildAuthHeaders(
            'POST',
            '/api/internal/reach/v1/content/drafts',
            '{"key":"value"}',
            'ikey-test',
            'req-test',
            'my-bearer-token',
            'my-signing-key-32-chars-xxxxxxxxxxx',
            'reach-v1',
            1
        );

        $expected = [
            'Authorization',
            'X-Reach-Key-Id',
            'X-Reach-Timestamp',
            'X-Reach-Nonce',
            'X-Reach-Signature',
            'X-Reach-Content-SHA256',
            'X-Request-ID',
            'X-Idempotency-Key',
            'X-Reach-API-Version',
            'Content-Type',
        ];

        foreach ($expected as $header) {
            $this->assertArrayHasKey($header, $headers, "Missing required header: {$header}");
            $this->assertNotEmpty($headers[$header], "Header {$header} must not be empty");
        }
    }

    public function testSignatureIsNotRepeated(): void
    {
        $body = '{"title":"Test"}';

        $h1 = $this->signer->buildAuthHeaders('POST', '/path', $body, 'k1', 'r1', 'bt', 'sk', 'kid', 1);
        $h2 = $this->signer->buildAuthHeaders('POST', '/path', $body, 'k2', 'r2', 'bt', 'sk', 'kid', 1);

        // Nonces differ so signatures differ
        $this->assertNotSame($h1['X-Reach-Signature'], $h2['X-Reach-Signature']);
    }

    public function testPayloadChecksumMatchesSha256OfBody(): void
    {
        $body    = '{"publish":true,"content_id":42}';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', $body, 'k', 'r', 'bt', 'sk', 'kid', 1);

        $this->assertSame(hash('sha256', $body), $headers['X-Reach-Content-SHA256']);
    }

    public function testContentTypeIsAlwaysJson(): void
    {
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', 'sk', 'kid', 1);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    public function testSignatureDoesNotContainBearerToken(): void
    {
        $bearer  = 'ultra-secret-bearer-token-12345';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', $bearer, 'sk', 'kid', 1);

        $this->assertStringNotContainsString($bearer, $headers['X-Reach-Signature']);
    }

    public function testSignatureDoesNotContainSigningKey(): void
    {
        $sigKey  = 'super-secret-signing-key-here';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', $sigKey, 'kid', 1);

        foreach ($headers as $name => $value) {
            $this->assertStringNotContainsString($sigKey, $value,
                "Header {$name} must not contain the signing key"
            );
        }
    }

    public function testApiVersionPropagatedCorrectly(): void
    {
        $headers = $this->signer->buildAuthHeaders('GET', '/path', '', 'k', 'r', 'bt', 'sk', 'kid', 1);
        $this->assertSame('1', $headers['X-Reach-API-Version']);
    }

    public function testKeyIdPropagatedToHeader(): void
    {
        $keyId   = 'reach-production-key-id';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', 'sk', $keyId, 1);
        $this->assertSame($keyId, $headers['X-Reach-Key-Id']);
    }

    public function testBearerAuthorizationFormat(): void
    {
        $bearer  = 'my-service-token';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', $bearer, 'sk', 'kid', 1);
        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
        $this->assertSame("Bearer {$bearer}", $headers['Authorization']);
    }
}

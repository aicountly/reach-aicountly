<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Connector\HmacSigner
 */
class HmacSignerTest extends CIUnitTestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new HmacSigner();
    }

    public function testBuildCanonicalStringReturnsCorrectFormat(): void
    {
        $canonical = $this->signer->buildCanonicalString(
            'POST',
            '/reach/v1/content/drafts',
            1752393600,
            'test-nonce-uuid',
            'abc123',
            'test-idempotency-key',
            1
        );

        $expected = "POST\n/reach/v1/content/drafts\n1752393600\ntest-nonce-uuid\nabc123\ntest-idempotency-key\n1";
        $this->assertSame($expected, $canonical);
    }

    public function testSignProducesDeterministicOutput(): void
    {
        $canonical = 'POST\n/reach/v1/content/drafts\n1234567890';
        $key       = 'test-signing-key-32-chars-long-x!';

        $sig1 = $this->signer->sign($canonical, $key);
        $sig2 = $this->signer->sign($canonical, $key);

        $this->assertSame($sig1, $sig2);
        $this->assertSame(64, strlen($sig1)); // hex sha256
    }

    public function testSignProducesDifferentSignaturesForDifferentKeys(): void
    {
        $canonical = 'POST\n/reach/v1/test';
        $sig1 = $this->signer->sign($canonical, 'key-one');
        $sig2 = $this->signer->sign($canonical, 'key-two');

        $this->assertNotSame($sig1, $sig2);
    }

    public function testVerifyReturnsTrueForMatchingSignatures(): void
    {
        $canonical = 'test-canonical-string';
        $key       = 'test-key';
        $sig       = $this->signer->sign($canonical, $key);

        $this->assertTrue($this->signer->verify($sig, $sig));
    }

    public function testVerifyReturnsFalseForMismatch(): void
    {
        $this->assertFalse($this->signer->verify('abc', 'def'));
    }

    public function testBodyHashMatchesExpected(): void
    {
        $body = '{"test": "payload"}';
        $hash = $this->signer->bodyHash($body);
        $this->assertSame(hash('sha256', $body), $hash);
    }

    public function testBodyHashOfEmptyStringIsValid(): void
    {
        $hash = $this->signer->bodyHash('');
        $this->assertSame(64, strlen($hash));
    }

    public function testBuildAuthHeadersReturnsAllRequiredHeaders(): void
    {
        $headers = $this->signer->buildAuthHeaders(
            'POST',
            '/reach/v1/content/drafts',
            '{"test":"data"}',
            'idempotency-key-123',
            'req-id-abc',
            'bearer-token-xyz',
            'signing-key-secret',
            'reach-v1',
            1
        );

        $requiredHeaders = [
            'Authorization', 'X-Reach-Key-Id', 'X-Reach-Timestamp',
            'X-Reach-Nonce', 'X-Reach-Signature', 'X-Reach-Content-SHA256',
            'X-Request-ID', 'X-Idempotency-Key', 'X-Reach-API-Version',
            'Content-Type',
        ];

        foreach ($requiredHeaders as $header) {
            $this->assertArrayHasKey($header, $headers, "Missing header: {$header}");
        }

        $this->assertSame('Bearer bearer-token-xyz', $headers['Authorization']);
        $this->assertSame('reach-v1', $headers['X-Reach-Key-Id']);
        $this->assertSame('1', $headers['X-Reach-API-Version']);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    public function testBuildAuthHeadersDoesNotExposeBearerTokenInsideSignature(): void
    {
        $bearerToken = 'super-secret-bearer-token';
        $headers     = $this->signer->buildAuthHeaders(
            'POST', '/reach/v1/test', 'body', 'ikey', 'reqid',
            $bearerToken, 'signing-key', 'reach-v1', 1
        );

        // Signature must not contain the bearer token
        $this->assertStringNotContainsString($bearerToken, $headers['X-Reach-Signature']);
    }

    public function testMethodIsUppercasedInCanonicalString(): void
    {
        $c1 = $this->signer->buildCanonicalString('get', '/path', 123, 'n', 'h', 'i', 1);
        $c2 = $this->signer->buildCanonicalString('GET', '/path', 123, 'n', 'h', 'i', 1);
        $this->assertSame($c1, $c2);
    }
}

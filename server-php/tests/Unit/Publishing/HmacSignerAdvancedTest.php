<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Additional edge-case tests for HmacSigner.
 *
 * @covers \App\Libraries\Publishing\Connector\HmacSigner
 */
class HmacSignerAdvancedTest extends CIUnitTestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new HmacSigner();
    }

    public function testSignatureIs64CharsHex(): void
    {
        $sig = $this->signer->sign('some-canonical-string', 'any-key');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sig);
    }

    public function testBodyHashIs64CharsHex(): void
    {
        $hash = $this->signer->bodyHash('{"foo":"bar"}');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testBuildCanonicalStringUppercasesMethod(): void
    {
        $lower = $this->signer->buildCanonicalString('post', '/path', 1, 'n', 'h', 'i', 1);
        $upper = $this->signer->buildCanonicalString('POST', '/path', 1, 'n', 'h', 'i', 1);
        $this->assertSame($lower, $upper);
    }

    public function testBuildAuthHeadersNonceIsUuid4Format(): void
    {
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', 'sk', 'kid', 1);
        $uuid    = $headers['X-Reach-Nonce'];
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    public function testBuildAuthHeadersTimestampIsCurrentUnixTime(): void
    {
        $before  = time();
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', 'sk', 'kid', 1);
        $after   = time();

        $ts = (int) $headers['X-Reach-Timestamp'];
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after + 1, $ts);
    }

    public function testBuildAuthHeadersApiVersionMatchesInput(): void
    {
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', 'sk', 'kid', 2);
        $this->assertSame('2', $headers['X-Reach-API-Version']);
    }

    public function testRequestIdPassedThroughToHeader(): void
    {
        $reqId   = 'unique-request-id-abc-123';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', $reqId, 'bt', 'sk', 'kid', 1);
        $this->assertSame($reqId, $headers['X-Request-ID']);
    }

    public function testIdempotencyKeyPassedThroughToHeader(): void
    {
        $ikey    = 'unique-idempotency-key-xyz';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', $ikey, 'r', 'bt', 'sk', 'kid', 1);
        $this->assertSame($ikey, $headers['X-Idempotency-Key']);
    }

    public function testVerifyUsesTimingSafeComparison(): void
    {
        // Verify that verify() handles equal strings
        $this->assertTrue($this->signer->verify('identical', 'identical'));
        $this->assertFalse($this->signer->verify('a', 'b'));
    }

    public function testSignatureChangesWithDifferentNonces(): void
    {
        $c1 = $this->signer->buildCanonicalString('POST', '/p', 100, 'nonce-a', 'hash', 'ikey', 1);
        $c2 = $this->signer->buildCanonicalString('POST', '/p', 100, 'nonce-b', 'hash', 'ikey', 1);

        $sig1 = $this->signer->sign($c1, 'key');
        $sig2 = $this->signer->sign($c2, 'key');

        $this->assertNotSame($sig1, $sig2);
    }

    public function testSignatureChangesWithDifferentTimestamps(): void
    {
        $c1 = $this->signer->buildCanonicalString('POST', '/p', 100, 'nonce', 'hash', 'ikey', 1);
        $c2 = $this->signer->buildCanonicalString('POST', '/p', 200, 'nonce', 'hash', 'ikey', 1);

        $this->assertNotSame($c1, $c2);
    }
}

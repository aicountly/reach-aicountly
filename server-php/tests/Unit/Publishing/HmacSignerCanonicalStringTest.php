<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests for HMAC canonical string construction according to API contract specification.
 *
 * @covers \App\Libraries\Publishing\Connector\HmacSigner
 */
class HmacSignerCanonicalStringTest extends CIUnitTestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new HmacSigner();
    }

    public function testCanonicalStringHasSevenParts(): void
    {
        $canonical = $this->signer->buildCanonicalString('POST', '/path', 1000, 'n', 'h', 'k', 1);
        $this->assertCount(7, explode("\n", $canonical));
    }

    public function testCanonicalStringMethodIsFirst(): void
    {
        $canonical = $this->signer->buildCanonicalString('POST', '/path', 1000, 'n', 'h', 'k', 1);
        $this->assertStringStartsWith('POST', $canonical);
    }

    public function testCanonicalStringPathIsSecond(): void
    {
        $canonical = $this->signer->buildCanonicalString('POST', '/api/v1/test', 1000, 'n', 'h', 'k', 1);
        $parts     = explode("\n", $canonical);
        $this->assertSame('/api/v1/test', $parts[1]);
    }

    public function testCanonicalStringTimestampIsThird(): void
    {
        $canonical = $this->signer->buildCanonicalString('POST', '/path', 1752393600, 'n', 'h', 'k', 1);
        $parts     = explode("\n", $canonical);
        $this->assertSame('1752393600', $parts[2]);
    }

    public function testCanonicalStringNonceIsFourth(): void
    {
        $canonical = $this->signer->buildCanonicalString('POST', '/path', 1000, 'unique-nonce', 'h', 'k', 1);
        $parts     = explode("\n", $canonical);
        $this->assertSame('unique-nonce', $parts[3]);
    }

    public function testCanonicalStringBodyHashIsFifth(): void
    {
        $canonical = $this->signer->buildCanonicalString('POST', '/path', 1000, 'n', 'body-hash-here', 'k', 1);
        $parts     = explode("\n", $canonical);
        $this->assertSame('body-hash-here', $parts[4]);
    }

    public function testCanonicalStringIdempotencyKeyIsSixth(): void
    {
        $canonical = $this->signer->buildCanonicalString('POST', '/path', 1000, 'n', 'h', 'my-idempotency-key', 1);
        $parts     = explode("\n", $canonical);
        $this->assertSame('my-idempotency-key', $parts[5]);
    }

    public function testCanonicalStringApiVersionIsSeventh(): void
    {
        $canonical = $this->signer->buildCanonicalString('POST', '/path', 1000, 'n', 'h', 'k', 2);
        $parts     = explode("\n", $canonical);
        $this->assertSame('2', $parts[6]);
    }

    public function testMethodIsNormalizedToUpperCase(): void
    {
        $lower = $this->signer->buildCanonicalString('get', '/path', 1000, 'n', 'h', 'k', 1);
        $upper = $this->signer->buildCanonicalString('GET', '/path', 1000, 'n', 'h', 'k', 1);
        $this->assertSame($lower, $upper);
    }

    public function testPutAndPostProduceDifferentCanonicals(): void
    {
        $c1 = $this->signer->buildCanonicalString('POST', '/path', 1000, 'n', 'h', 'k', 1);
        $c2 = $this->signer->buildCanonicalString('PUT', '/path', 1000, 'n', 'h', 'k', 1);
        $this->assertNotSame($c1, $c2);
    }
}

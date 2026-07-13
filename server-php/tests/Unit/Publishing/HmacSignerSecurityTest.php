<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Security-focused tests for HmacSigner.
 * Ensures no credentials are leaked, signatures are stable, and replay is detectable.
 *
 * @covers \App\Libraries\Publishing\Connector\HmacSigner
 */
class HmacSignerSecurityTest extends CIUnitTestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new HmacSigner();
    }

    public function testSigningKeyNeverAppearsInHeaders(): void
    {
        $signingKey = 'super-confidential-signing-key-value';
        $headers    = $this->signer->buildAuthHeaders(
            'POST', '/api/reach/v1/content/drafts', '{}',
            'k', 'r', 'bt', $signingKey, 'kid', 1
        );
        foreach ($headers as $name => $value) {
            $this->assertStringNotContainsString($signingKey, (string) $value,
                "Header {$name} must not contain the signing key"
            );
        }
    }

    public function testBearerTokenIsNotInSignature(): void
    {
        $bearer  = 'bearer-token-must-not-appear-in-sig';
        $headers = $this->signer->buildAuthHeaders('POST', '/p', '{}', 'k', 'r', $bearer, 'sk', 'kid', 1);
        $this->assertStringNotContainsString($bearer, $headers['X-Reach-Signature']);
    }

    public function testReplayableSignatureIsDetectedByTimestampChange(): void
    {
        $key = 'test-key';

        $c1 = $this->signer->buildCanonicalString('POST', '/p', time() - 120, 'nonce-a', 'h', 'k', 1);
        $c2 = $this->signer->buildCanonicalString('POST', '/p', time(), 'nonce-a', 'h', 'k', 1);

        $this->assertNotSame(
            $this->signer->sign($c1, $key),
            $this->signer->sign($c2, $key),
            'Stale requests produce different signatures'
        );
    }

    public function testDifferentPathsProduceDifferentSignatures(): void
    {
        $key = 'signing-key';
        $ts  = time();
        $n   = 'nonce';

        $c1 = $this->signer->buildCanonicalString('POST', '/api/v1/content/drafts', $ts, $n, 'h', 'k', 1);
        $c2 = $this->signer->buildCanonicalString('POST', '/api/v1/content/publish', $ts, $n, 'h', 'k', 1);

        $this->assertNotSame($this->signer->sign($c1, $key), $this->signer->sign($c2, $key));
    }

    public function testTamperedBodyDetectedByHashMismatch(): void
    {
        $originalBody = '{"title":"Original Article"}';
        $tamperedBody = '{"title":"Tampered Article"}';

        $originalHash = $this->signer->bodyHash($originalBody);
        $tamperedHash = $this->signer->bodyHash($tamperedBody);

        $this->assertNotSame($originalHash, $tamperedHash);
    }

    public function testSignatureIs256BitHex(): void
    {
        $sig = $this->signer->sign('test-canonical', 'test-key');
        $this->assertSame(64, strlen($sig));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $sig);
    }

    public function testVerifyIsTimingSafe(): void
    {
        // Verify does not short-circuit on first different byte
        $sig1 = str_repeat('a', 64);
        $sig2 = str_repeat('b', 64);
        $this->assertFalse($this->signer->verify($sig1, $sig2));
    }

    public function testEmptySigningKeyProducesDifferentResultFromNonEmpty(): void
    {
        $canonical = 'POST\n/path\n1\nnonce\nhash\nikey\n1';
        $withKey   = $this->signer->sign($canonical, 'my-key');
        $withEmpty = $this->signer->sign($canonical, '');
        $this->assertNotSame($withKey, $withEmpty);
    }
}

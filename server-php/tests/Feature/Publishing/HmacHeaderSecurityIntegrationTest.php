<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Security integration tests for HMAC header generation.
 * Validates that no sensitive data is ever exposed through headers.
 *
 * @group publishing
 */
class HmacHeaderSecurityIntegrationTest extends CIUnitTestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new HmacSigner();
    }

    public function testNoCredentialLeakInHeaderSet(): void
    {
        $signingKey  = 'test-signing-key-should-never-appear';
        $bearerToken = 'test-bearer-token-should-not-leak';

        $headers = $this->signer->buildAuthHeaders(
            'POST', '/api/reach/v1/content/drafts', '{"title":"Article"}',
            'ikey', 'req', $bearerToken, $signingKey, 'kid', 1
        );

        foreach ($headers as $name => $value) {
            $this->assertStringNotContainsString($signingKey, (string) $value,
                "Header {$name} must not contain signing key"
            );
        }

        // Bearer token is OK in Authorization header only
        $this->assertSame("Bearer {$bearerToken}", $headers['Authorization']);
        $this->assertStringNotContainsString($bearerToken, $headers['X-Reach-Signature']);
    }

    public function testDifferentPayloadsProduceDifferentChecksums(): void
    {
        $body1 = '{"title":"Article A","content":"Content 1"}';
        $body2 = '{"title":"Article B","content":"Content 2"}';

        $h1 = $this->signer->buildAuthHeaders('POST', '/path', $body1, 'k1', 'r1', 'bt', 'sk', 'kid', 1);
        $h2 = $this->signer->buildAuthHeaders('POST', '/path', $body2, 'k2', 'r2', 'bt', 'sk', 'kid', 1);

        $this->assertNotSame($h1['X-Reach-Content-SHA256'], $h2['X-Reach-Content-SHA256']);
    }

    public function testTimestampIsCurrentUnixSecond(): void
    {
        $before  = time();
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', 'sk', 'kid', 1);
        $after   = time();

        $ts = (int) $headers['X-Reach-Timestamp'];
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after + 1, $ts);
    }

    public function testEachRequestHasUniqueNonce(): void
    {
        $nonces = [];
        for ($i = 0; $i < 10; $i++) {
            $headers  = $this->signer->buildAuthHeaders('POST', '/path', '{}', "k{$i}", "r{$i}", 'bt', 'sk', 'kid', 1);
            $nonces[] = $headers['X-Reach-Nonce'];
        }

        $this->assertCount(10, array_unique($nonces), 'All nonces must be unique');
    }

    public function testSignatureIncorporatesNonceInCanonical(): void
    {
        $ts = time();

        $c1 = $this->signer->buildCanonicalString('POST', '/p', $ts, 'nonce-A', 'hash', 'ikey', 1);
        $c2 = $this->signer->buildCanonicalString('POST', '/p', $ts, 'nonce-B', 'hash', 'ikey', 1);

        $key = 'test-signing-key';
        $this->assertNotSame($this->signer->sign($c1, $key), $this->signer->sign($c2, $key));
    }

    public function testSignatureWithSameInputsIsReproducible(): void
    {
        $canonical = 'POST\n/path\n1000\nnonce\nhash\nikey\n1';
        $key       = 'stable-key';

        $sig1 = $this->signer->sign($canonical, $key);
        $sig2 = $this->signer->sign($canonical, $key);

        $this->assertSame($sig1, $sig2);
    }

    public function testBodyHashAlgorithmIsSha256(): void
    {
        $body = '{"test":"body"}';
        $hash = $this->signer->bodyHash($body);

        $expected = hash('sha256', $body);
        $this->assertSame($expected, $hash);
    }
}

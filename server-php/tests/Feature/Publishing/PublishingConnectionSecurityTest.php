<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\HmacSigner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Security tests verifying that secrets are never exposed through the publishing pipeline.
 *
 * @group publishing
 */
class PublishingConnectionSecurityTest extends CIUnitTestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new HmacSigner();
    }

    public function testSigningKeyNotInAnyHeader(): void
    {
        $key     = 'highly-confidential-signing-key-test';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', $key, 'kid', 1);

        foreach ($headers as $name => $value) {
            $this->assertStringNotContainsString($key, (string) $value,
                "Signing key must not appear in header {$name}"
            );
        }
    }

    public function testServiceTokenOnlyInAuthorizationHeader(): void
    {
        $token   = 'my-service-token-xyz';
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', $token, 'sk', 'kid', 1);

        // Token should appear in Authorization header
        $this->assertStringContainsString($token, $headers['Authorization']);

        // Token should NOT appear in any other header
        foreach ($headers as $name => $value) {
            if ($name !== 'Authorization') {
                $this->assertStringNotContainsString($token, (string) $value,
                    "Service token must not appear in header {$name}"
                );
            }
        }
    }

    public function testHeaderValuesAreStrings(): void
    {
        $headers = $this->signer->buildAuthHeaders('POST', '/path', '{}', 'k', 'r', 'bt', 'sk', 'kid', 1);
        foreach ($headers as $name => $value) {
            $this->assertIsString($value, "Header {$name} must be a string");
        }
    }

    public function testNonceIsNotPredictable(): void
    {
        $nonces = [];
        for ($i = 0; $i < 20; $i++) {
            $headers  = $this->signer->buildAuthHeaders('POST', '/path', '{}', "k{$i}", "r{$i}", 'bt', 'sk', 'kid', 1);
            $nonces[] = $headers['X-Reach-Nonce'];
        }
        $unique = array_unique($nonces);
        $this->assertCount(20, $unique, 'All nonces must be unique');
    }

    public function testSignatureChangesWhenBodyChanges(): void
    {
        $body1 = '{"action":"publish"}';
        $body2 = '{"action":"unpublish"}';

        $h1 = $this->signer->buildAuthHeaders('POST', '/path', $body1, 'k1', 'r1', 'bt', 'sk', 'kid', 1);
        $h2 = $this->signer->buildAuthHeaders('POST', '/path', $body2, 'k2', 'r2', 'bt', 'sk', 'kid', 1);

        $this->assertNotSame($h1['X-Reach-Content-SHA256'], $h2['X-Reach-Content-SHA256']);
        $this->assertNotSame($h1['X-Reach-Signature'], $h2['X-Reach-Signature']);
    }
}

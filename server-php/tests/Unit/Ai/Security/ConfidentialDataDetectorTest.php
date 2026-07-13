<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Security;

use App\Libraries\Ai\Security\ConfidentialDataDetector;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Security\ConfidentialDataDetector
 */
class ConfidentialDataDetectorTest extends CIUnitTestCase
{
    private ConfidentialDataDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new ConfidentialDataDetector();
    }

    public function testCleanTextIsClean(): void
    {
        $text = 'Our SaaS product offers real-time collaboration and automated workflows.';
        $this->assertTrue($this->detector->isClean($text));
        $this->assertEmpty($this->detector->detect($text));
    }

    public function testAwsKeyDetected(): void
    {
        $this->assertFalse($this->detector->isClean('key: AKIAIOSFODNN7EXAMPLE'));
    }

    public function testOpenAiKeyDetected(): void
    {
        $key  = 'sk-' . str_repeat('A', 48);
        $text = "Use key: {$key}";
        $this->assertFalse($this->detector->isClean($text));
        $detections = $this->detector->detect($text);
        $this->assertNotEmpty($detections);
    }

    public function testPrivateKeyDetected(): void
    {
        $text = "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAK\n-----END RSA PRIVATE KEY-----";
        $this->assertFalse($this->detector->isClean($text));
    }

    public function testPasswordDetected(): void
    {
        $this->assertFalse($this->detector->isClean('password=supersecret123'));
    }

    public function testJwtDetected(): void
    {
        // Realistic JWT: header.payload.signature — each segment needs 20+ chars after "eyJ"
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9' .
               '.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ' .
               '.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        $this->assertFalse($this->detector->isClean($jwt));
    }

    public function testRedactReplacesSensitiveData(): void
    {
        $key    = 'sk-' . str_repeat('B', 48);
        $result = $this->detector->redact("apikey: {$key}");
        $this->assertStringContainsString('[REDACTED:', $result);
        $this->assertStringNotContainsString($key, $result);
    }

    public function testInternalMarkerDetected(): void
    {
        $this->assertFalse($this->detector->isClean('This product [INTERNAL] has a bug'));
    }
}

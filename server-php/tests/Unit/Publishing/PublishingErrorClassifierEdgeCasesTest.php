<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Edge-case tests for PublishingErrorClassifier.
 *
 * @covers \App\Libraries\Publishing\Connector\PublishingErrorClassifier
 */
class PublishingErrorClassifierEdgeCasesTest extends CIUnitTestCase
{
    private PublishingErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new PublishingErrorClassifier();
    }

    public function testHttp200NotClassifiedAsError(): void
    {
        $this->assertSame('unknown_error', $this->classifier->classifyHttpStatus(200));
    }

    public function testHttp299NotClassifiedAsError(): void
    {
        $this->assertSame('unknown_error', $this->classifier->classifyHttpStatus(299));
    }

    public function testHttp500IsServerError(): void
    {
        $this->assertSame('server_error', $this->classifier->classifyHttpStatus(500));
    }

    public function testHttp501IsServerError(): void
    {
        $this->assertSame('server_error', $this->classifier->classifyHttpStatus(501));
    }

    public function testHttp503IsServerError(): void
    {
        $this->assertSame('server_error', $this->classifier->classifyHttpStatus(503));
    }

    public function testSafeMessageDoesNotContainPrivateData(): void
    {
        $categories = array_keys([
            'authentication_error' => true, 'signature_error' => true, 'rate_limited' => true,
            'validation_error' => true, 'version_conflict' => true, 'checksum_mismatch' => true,
            'not_found' => true, 'timeout' => true, 'network_error' => true,
            'server_error' => true, 'publication_blocked' => true,
        ]);

        foreach ($categories as $cat) {
            $msg = $this->classifier->safeMessage($cat, 500);
            $this->assertNotEmpty($msg, "Empty message for {$cat}");
            $this->assertStringNotContainsString('Bearer', $msg);
            $this->assertStringNotContainsString('password', $msg);
            $this->assertStringNotContainsString('secret', strtolower($msg));
        }
    }

    public function testClassifyExceptionSslError(): void
    {
        $e = new \RuntimeException('SSL certificate problem: self-signed certificate');
        $this->assertSame('network_error', $this->classifier->classifyException($e));
    }

    public function testClassifyExceptionConnectionRefused(): void
    {
        $e = new \RuntimeException('Connection refused');
        $this->assertSame('network_error', $this->classifier->classifyException($e));
    }

    public function testClassifyExceptionGenericIsNetworkError(): void
    {
        $e = new \RuntimeException('Some unknown network issue');
        $this->assertSame('network_error', $this->classifier->classifyException($e));
    }

    public function testRetryableConstantsAreCorrect(): void
    {
        $expected = ['rate_limited', 'timeout', 'network_error', 'server_error'];
        $this->assertSame($expected, PublishingErrorClassifier::RETRYABLE_CATEGORIES);
    }

    public function testChecksumMismatchIsNotRetryable(): void
    {
        $this->assertFalse($this->classifier->isRetryable('checksum_mismatch'));
    }

    public function testSignatureErrorIsNotRetryable(): void
    {
        $this->assertFalse($this->classifier->isRetryable('signature_error'));
    }

    public function testVersionConflictIsNotRetryable(): void
    {
        $this->assertFalse($this->classifier->isRetryable('version_conflict'));
    }
}

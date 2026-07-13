<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Connector\PublishingErrorClassifier
 */
class PublishingErrorClassifierTest extends CIUnitTestCase
{
    private PublishingErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new PublishingErrorClassifier();
    }

    /** @dataProvider httpStatusProvider */
    public function testClassifyHttpStatus(int $status, string $expectedCategory): void
    {
        $this->assertSame($expectedCategory, $this->classifier->classifyHttpStatus($status));
    }

    public static function httpStatusProvider(): array
    {
        return [
            [401, 'authentication_error'],
            [403, 'publication_blocked'],
            [404, 'not_found'],
            [409, 'version_conflict'],
            [422, 'validation_error'],
            [429, 'rate_limited'],
            [400, 'validation_error'],
            [504, 'timeout'],
            [500, 'server_error'],
            [503, 'server_error'],
            [299, 'unknown_error'],
        ];
    }

    public function testRetryableCategories(): void
    {
        $retryable    = ['rate_limited', 'timeout', 'network_error', 'server_error'];
        $nonRetryable = ['authentication_error', 'validation_error', 'not_found', 'publication_blocked', 'version_conflict'];

        foreach ($retryable as $cat) {
            $this->assertTrue($this->classifier->isRetryable($cat), "{$cat} should be retryable");
        }
        foreach ($nonRetryable as $cat) {
            $this->assertFalse($this->classifier->isRetryable($cat), "{$cat} should not be retryable");
        }
    }

    public function testSafeMessageNeverEmpty(): void
    {
        $categories = ['authentication_error','signature_error','rate_limited','validation_error',
                       'not_found','timeout','network_error','server_error','configuration_error','unknown'];

        foreach ($categories as $cat) {
            $msg = $this->classifier->safeMessage($cat);
            $this->assertNotEmpty($msg, "Safe message should not be empty for {$cat}");
        }
    }

    public function testClassifyExceptionForTimeout(): void
    {
        $e = new \RuntimeException('cURL timeout');
        $this->assertSame('timeout', $this->classifier->classifyException($e));
    }

    public function testClassifyExceptionForNetworkError(): void
    {
        $e = new \RuntimeException('Could not resolve host: aicountly.com');
        $this->assertSame('network_error', $this->classifier->classifyException($e));
    }
}

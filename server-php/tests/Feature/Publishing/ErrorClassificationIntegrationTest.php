<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for error classification and retry policy.
 *
 * @group publishing
 */
class ErrorClassificationIntegrationTest extends CIUnitTestCase
{
    private PublishingErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new PublishingErrorClassifier();
    }

    /** @dataProvider errorCategoryRetryMatrix */
    public function testErrorRetryPolicy(int $httpStatus, bool $shouldBeRetryable): void
    {
        $category = $this->classifier->classifyHttpStatus($httpStatus);
        $this->assertSame($shouldBeRetryable, $this->classifier->isRetryable($category),
            "HTTP {$httpStatus} should " . ($shouldBeRetryable ? '' : 'not ') . "be retryable"
        );
    }

    public static function errorCategoryRetryMatrix(): array
    {
        return [
            [429, true],   // Rate limited — retry
            [500, true],   // Internal server error — retry
            [503, true],   // Service unavailable — retry
            [504, true],   // Gateway timeout — retry
            [401, false],  // Auth error — no retry
            [403, false],  // Forbidden — no retry
            [404, false],  // Not found — no retry
            [409, false],  // Conflict — no retry
            [422, false],  // Validation error — no retry
            [400, false],  // Bad request — no retry
        ];
    }

    public function testTimeoutExceptionIsRetryable(): void
    {
        $e        = new \RuntimeException('cURL error: timeout was reached');
        $category = $this->classifier->classifyException($e);
        $this->assertSame('timeout', $category);
        $this->assertTrue($this->classifier->isRetryable($category));
    }

    public function testNetworkErrorIsRetryable(): void
    {
        $e        = new \RuntimeException('Could not resolve host: aicountly.com');
        $category = $this->classifier->classifyException($e);
        $this->assertSame('network_error', $category);
        $this->assertTrue($this->classifier->isRetryable($category));
    }

    public function testSafeMessagesDoNotLeakInternalInfo(): void
    {
        $internalValues = [
            'Bearer eyJhbGc',   // JWT token
            'postgres://user',  // DB connection
            '/var/www/',        // Server path
            'sk-proj-',         // OpenAI key pattern
        ];

        $categories = ['authentication_error', 'server_error', 'network_error', 'validation_error'];

        foreach ($categories as $cat) {
            $msg = $this->classifier->safeMessage($cat, 500);
            foreach ($internalValues as $internal) {
                $this->assertStringNotContainsString($internal, $msg,
                    "Safe message for {$cat} must not contain '{$internal}'"
                );
            }
        }
    }

    public function testAll5xxCodesAreServerError(): void
    {
        $retryableCodes = [500, 501, 502, 503, 504, 507, 508];
        foreach ($retryableCodes as $code) {
            $cat = $this->classifier->classifyHttpStatus($code);
            // 504 is classified as timeout, rest as server_error; both are retryable
            $this->assertTrue($this->classifier->isRetryable($cat), "HTTP {$code} should be retryable");
        }
    }

    public function testAll4xxNonRetryableCodes(): void
    {
        $nonRetryable = [400, 401, 403, 404, 405, 409, 410, 422];
        foreach ($nonRetryable as $code) {
            $cat = $this->classifier->classifyHttpStatus($code);
            $this->assertFalse($this->classifier->isRetryable($cat), "HTTP {$code} should NOT be retryable");
        }
    }
}

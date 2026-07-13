<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Parametric tests covering all relevant HTTP status codes.
 *
 * @covers \App\Libraries\Publishing\Connector\PublishingErrorClassifier
 */
class PublishingErrorClassifierHttpCodesTest extends CIUnitTestCase
{
    private PublishingErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new PublishingErrorClassifier();
    }

    /** @dataProvider httpCodeCategoryMap */
    public function testHttpCodeClassification(int $code, string $expectedCategory): void
    {
        $this->assertSame($expectedCategory, $this->classifier->classifyHttpStatus($code));
    }

    public static function httpCodeCategoryMap(): array
    {
        return [
            [400, 'validation_error'],
            [401, 'authentication_error'],
            [403, 'publication_blocked'],
            [404, 'not_found'],
            [405, 'unknown_error'],
            [408, 'unknown_error'],
            [409, 'version_conflict'],
            [410, 'unknown_error'],
            [422, 'validation_error'],
            [429, 'rate_limited'],
            [500, 'server_error'],
            [502, 'server_error'],
            [503, 'server_error'],
            [504, 'timeout'],
            [507, 'server_error'],
            [100, 'unknown_error'],
            [301, 'unknown_error'],
        ];
    }

    /** @dataProvider retryableStatusCodes */
    public function testRetryableStatusCodesAreIdentified(int $code): void
    {
        $category = $this->classifier->classifyHttpStatus($code);
        $this->assertTrue($this->classifier->isRetryable($category),
            "HTTP {$code} → {$category} should be retryable"
        );
    }

    public static function retryableStatusCodes(): array
    {
        return [[429], [500], [502], [503], [504], [507]];
    }

    /** @dataProvider nonRetryableStatusCodes */
    public function testNonRetryableStatusCodesAreIdentified(int $code): void
    {
        $category = $this->classifier->classifyHttpStatus($code);
        $this->assertFalse($this->classifier->isRetryable($category),
            "HTTP {$code} → {$category} should NOT be retryable"
        );
    }

    public static function nonRetryableStatusCodes(): array
    {
        return [[400], [401], [403], [404], [409], [410], [422]];
    }

    public function testSafeMessageFor500IncludesStatusCode(): void
    {
        $msg = $this->classifier->safeMessage('server_error', 503);
        $this->assertStringContainsString('503', $msg);
    }
}

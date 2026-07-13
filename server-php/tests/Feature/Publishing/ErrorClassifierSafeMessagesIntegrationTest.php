<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for safe error messages from PublishingErrorClassifier.
 *
 * @group publishing
 */
class ErrorClassifierSafeMessagesIntegrationTest extends CIUnitTestCase
{
    private PublishingErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new PublishingErrorClassifier();
    }

    public function testAllCategoriesHaveNonEmptySafeMessages(): void
    {
        $categories = [
            'authentication_error', 'signature_error', 'replay_rejected',
            'rate_limited', 'validation_error', 'version_conflict',
            'checksum_mismatch', 'not_found', 'timeout', 'network_error',
            'server_error', 'publication_blocked', 'configuration_error',
        ];

        foreach ($categories as $category) {
            $message = $this->classifier->safeMessage($category);
            $this->assertNotEmpty($message, "Safe message for '{$category}' must not be empty");
            $this->assertGreaterThan(5, strlen($message), "Safe message for '{$category}' must be descriptive");
        }
    }

    public function testUnknownCategoryReturnsDefaultMessage(): void
    {
        $message = $this->classifier->safeMessage('completely_unknown_category');
        $this->assertNotEmpty($message);
    }

    public function testNoSafeMessageContainsSensitivePatterns(): void
    {
        $sensitive = ['/Bearer\s/', '/password/i', '/secret/i', '/private/i', '/\d{4}-\d{4}-\d{4}/'];

        $categories = ['authentication_error', 'signature_error', 'server_error',
                       'network_error', 'timeout', 'validation_error'];

        foreach ($categories as $category) {
            $msg = $this->classifier->safeMessage($category, 500);
            foreach ($sensitive as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $msg,
                    "Safe message for {$category} must not match {$pattern}"
                );
            }
        }
    }

    public function testServerErrorIncludesHttpStatusWhenProvided(): void
    {
        $msg500 = $this->classifier->safeMessage('server_error', 500);
        $msg503 = $this->classifier->safeMessage('server_error', 503);

        $this->assertStringContainsString('500', $msg500);
        $this->assertStringContainsString('503', $msg503);
    }

    public function testClassifyExceptionForVariousMessages(): void
    {
        $cases = [
            ['cURL error 28: Operation timed out', 'timeout'],
            ['Could not resolve host: aicountly.com', 'network_error'],
            ['SSL handshake failed', 'network_error'],
            ['Connection refused', 'network_error'],
        ];

        foreach ($cases as [$message, $expectedCategory]) {
            $e        = new \RuntimeException($message);
            $category = $this->classifier->classifyException($e);
            $this->assertSame($expectedCategory, $category,
                "Exception '{$message}' should classify as '{$expectedCategory}'"
            );
        }
    }
}

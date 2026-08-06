<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\AiErrorClassifier;
use App\Libraries\Ai\AiProviderError;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\AiErrorClassifier
 */
class AiErrorClassifierTest extends CIUnitTestCase
{
    private AiErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new AiErrorClassifier();
    }

    public function testClassifiesAuthenticationError(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('Invalid API key'));
        $this->assertSame(AiProviderError::CATEGORY_AUTHENTICATION, $error->category);
    }

    public function testClassifiesRateLimited(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('Rate limit exceeded 429'));
        $this->assertSame(AiProviderError::CATEGORY_RATE_LIMITED, $error->category);
        $this->assertTrue($error->isRetryable());
    }

    /**
     * A retired model must reach the fallback resolver. Gemini reports this as
     * a 404 whose message would otherwise fall through to invalid_request, and
     * before that to 'unknown' — neither of which allows a fallback hop, so the
     * whole generation dead-ended on a model that can never work again.
     */
    public function testClassifiesRetiredModelAsProviderUnavailable(): void
    {
        $messages = [
            'This model models/gemini-2.5-pro is no longer available to new users. Please update your code to use a newer model.',
            'models/gemini-1.0-pro is not found for API version v1beta',
            'The model `gpt-4-vision-preview` has been deprecated',
            'The model `gpt-9` does not exist or you do not have access to it',
        ];

        foreach ($messages as $message) {
            $error = $this->classifier->classify(new \RuntimeException($message));
            $this->assertSame(
                AiProviderError::CATEGORY_PROVIDER_UNAVAIL,
                $error->category,
                "Retired-model message should route to a fallback: {$message}",
            );
        }
    }

    /**
     * gpt-5-mini rejecting the legacy 'max_tokens' classified as 'unknown',
     * which is both uninformative and the one category that permits neither a
     * retry nor a fallback hop — so an adapter bug looked like an unexplained
     * provider failure.
     */
    public function testClassifiesRejectedRequestShapeAsInvalidRequest(): void
    {
        $messages = [
            "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.",
            "Unsupported value: 'temperature' does not support 0.2 with this model.",
            'Unrecognized request argument supplied: max_completion_tokens',
        ];

        foreach ($messages as $message) {
            $error = $this->classifier->classify(new \RuntimeException($message));
            $this->assertSame(
                AiProviderError::CATEGORY_INVALID_REQUEST,
                $error->category,
                "Request-shape rejection should not fall through to 'unknown': {$message}",
            );
        }
    }

    public function testClassifiesTimeout(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('Connection timed out'));
        $this->assertSame(AiProviderError::CATEGORY_TIMEOUT, $error->category);
        $this->assertTrue($error->isRetryable());
    }

    public function testClassifiesContextLimit(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('This model\'s maximum context length'));
        $this->assertSame(AiProviderError::CATEGORY_CONTEXT_LIMIT, $error->category);
        $this->assertFalse($error->isRetryable());
    }

    public function testClassifiesMalformedOutput(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('failed to decode json response'));
        $this->assertSame(AiProviderError::CATEGORY_MALFORMED_OUTPUT, $error->category);
        $this->assertTrue($error->isRetryable());
    }

    public function testClassifiesContentBlocked(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('Content blocked by content_filter policy'));
        $this->assertSame(AiProviderError::CATEGORY_CONTENT_BLOCKED, $error->category);
        $this->assertFalse($error->isRetryable());
    }

    public function testFallsBackToUnknownButKeepsMessage(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('Some completely unexpected error'));
        $this->assertSame(AiProviderError::CATEGORY_UNKNOWN, $error->category);
        $this->assertSame('Some completely unexpected error', $error->message);
    }

    public function testClassifiesInvalidSchemaRequest(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('Invalid schema for response_format[\'json_schema\']'));
        $this->assertSame(AiProviderError::CATEGORY_INVALID_REQUEST, $error->category);
        $this->assertStringContainsString('json_schema', $error->message);
    }
}

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

    public function testFallsBackToUnknown(): void
    {
        $error = $this->classifier->classify(new \RuntimeException('Some completely unexpected error'));
        $this->assertSame(AiProviderError::CATEGORY_UNKNOWN, $error->category);
    }
}

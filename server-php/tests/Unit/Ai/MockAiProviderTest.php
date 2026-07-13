<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderException;
use App\Libraries\Ai\Providers\MockAiProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Providers\MockAiProvider
 */
class MockAiProviderTest extends CIUnitTestCase
{
    private function input(): AiGenerationInput
    {
        return new AiGenerationInput(
            systemPrompt:    'You are a helpful assistant.',
            userPrompt:      'Generate a blog post about accounting.',
            outputSchema:    [],
            modelKey:        'mock-model',
            maxOutputTokens: 1024,
        );
    }

    public function testIsAlwaysConfigured(): void
    {
        $provider = new MockAiProvider();
        $this->assertTrue($provider->isConfigured());
    }

    public function testProviderKey(): void
    {
        $provider = new MockAiProvider();
        $this->assertSame('mock', $provider->getProviderKey());
    }

    public function testSuccessScenarioReturnsValidResult(): void
    {
        $provider = new MockAiProvider('success');
        $result   = $provider->generate($this->input());

        $this->assertSame('mock', $result->providerKey);
        $this->assertSame(150, $result->inputTokens);
        $this->assertSame(350, $result->outputTokens);
        $this->assertIsArray($result->parsedJson);
        $this->assertArrayHasKey('title', $result->parsedJson);
    }

    public function testMalformedScenarioReturnsBadJson(): void
    {
        $provider = new MockAiProvider('malformed');
        $result   = $provider->generate($this->input());

        $this->assertNull($result->parsedJson);
        $this->assertStringContainsString('{{{', $result->rawContent);
    }

    public function testRetryableErrorScenarioThrowsRetryableException(): void
    {
        $provider = new MockAiProvider('retryable_error');

        $this->expectException(AiProviderException::class);

        try {
            $provider->generate($this->input());
        } catch (AiProviderException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertSame(AiProviderError::CATEGORY_RATE_LIMITED, $e->getProviderError()->category);
            throw $e;
        }
    }

    public function testTerminalErrorThrowsNonRetryableException(): void
    {
        $provider = new MockAiProvider('terminal_error');

        $this->expectException(AiProviderException::class);

        try {
            $provider->generate($this->input());
        } catch (AiProviderException $e) {
            $this->assertFalse($e->isRetryable());
            $this->assertSame(AiProviderError::CATEGORY_AUTHENTICATION, $e->getProviderError()->category);
            throw $e;
        }
    }

    public function testCustomOutputIsReturned(): void
    {
        $custom   = ['title' => 'Custom Title', 'body' => 'Custom body.'];
        $provider = new MockAiProvider('success', $custom);
        $result   = $provider->generate($this->input());

        $this->assertSame('Custom Title', $result->parsedJson['title']);
    }

    public function testHealthCheckReturnsTrueForSuccess(): void
    {
        $health = (new MockAiProvider('success'))->healthCheck();
        $this->assertTrue($health->healthy);
    }

    public function testHealthCheckReturnsFalseForRetryableError(): void
    {
        $health = (new MockAiProvider('retryable_error'))->healthCheck();
        $this->assertFalse($health->healthy);
    }
}

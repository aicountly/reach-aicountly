<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\Providers\PerplexityProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Providers\PerplexityProvider
 */
class PerplexityProviderTest extends CIUnitTestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = [
            'AI_PERPLEXITY_API_KEY'          => $_ENV['AI_PERPLEXITY_API_KEY'] ?? null,
            'AI_PERPLEXITY_BASE_URL'         => $_ENV['AI_PERPLEXITY_BASE_URL'] ?? null,
            'AI_PERPLEXITY_DEFAULT_MODEL'    => $_ENV['AI_PERPLEXITY_DEFAULT_MODEL'] ?? null,
            'AI_PERPLEXITY_HEALTHCHECK_ALLOW_SPEND' => $_ENV['AI_PERPLEXITY_HEALTHCHECK_ALLOW_SPEND'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        parent::tearDown();
    }

    public function testIsConfiguredFalseWhenEnvAbsent(): void
    {
        unset($_ENV['AI_PERPLEXITY_API_KEY']);

        $provider = new PerplexityProvider();
        $this->assertFalse($provider->isConfigured());
    }

    public function testIsConfiguredTrueWhenEnvSet(): void
    {
        $_ENV['AI_PERPLEXITY_API_KEY'] = 'pplx-test-key';

        $provider = new PerplexityProvider();
        $this->assertTrue($provider->isConfigured());
    }

    public function testGetProviderKey(): void
    {
        $provider = new PerplexityProvider();
        $this->assertSame('perplexity', $provider->getProviderKey());
    }

    public function testResolveModelKeyUsesInputWhenProvided(): void
    {
        $provider = new PerplexityProvider();
        $this->assertSame('custom-model', $provider->resolveModelKey('custom-model'));
    }

    public function testResolveModelKeyUsesEnvDefaultWhenInputEmpty(): void
    {
        $_ENV['AI_PERPLEXITY_DEFAULT_MODEL'] = 'sonar-medium-online';

        $provider = new PerplexityProvider();
        $this->assertSame('sonar-medium-online', $provider->resolveModelKey(null));
        $this->assertSame('sonar-medium-online', $provider->resolveModelKey(''));
    }

    public function testResolveModelKeyFallsBackToSonarPro(): void
    {
        unset($_ENV['AI_PERPLEXITY_DEFAULT_MODEL']);

        $provider = new PerplexityProvider();
        $this->assertSame('sonar-pro', $provider->resolveModelKey(null));
    }

    public function testHealthCheckSkipsSpendWhenNotAllowed(): void
    {
        $_ENV['AI_PERPLEXITY_API_KEY'] = 'pplx-test-key';
        unset($_ENV['AI_PERPLEXITY_HEALTHCHECK_ALLOW_SPEND']);

        $health = (new PerplexityProvider())->healthCheck();

        $this->assertTrue($health->healthy);
        $this->assertSame('Configured; live check skipped to avoid spend.', $health->errorMessage);
    }

    public function testClassifyErrorReturnsAiProviderError(): void
    {
        $provider = new PerplexityProvider();
        $error    = $provider->classifyError(new \RuntimeException('rate limit exceeded'));

        $this->assertInstanceOf(AiProviderError::class, $error);
        $this->assertSame(AiProviderError::CATEGORY_RATE_LIMITED, $error->category);
    }
}

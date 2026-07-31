<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\Providers\GeminiProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Providers\GeminiProvider
 */
class GeminiProviderTest extends CIUnitTestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = [
            'AI_GEMINI_API_KEY'     => $_ENV['AI_GEMINI_API_KEY'] ?? null,
            'AI_GEMINI_BASE_URL'    => $_ENV['AI_GEMINI_BASE_URL'] ?? null,
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
        unset($_ENV['AI_GEMINI_API_KEY']);

        $provider = new GeminiProvider();
        $this->assertFalse($provider->isConfigured());
    }

    public function testIsConfiguredTrueWhenEnvSet(): void
    {
        $_ENV['AI_GEMINI_API_KEY'] = 'test-gemini-key';

        $provider = new GeminiProvider();
        $this->assertTrue($provider->isConfigured());
    }

    public function testGetProviderKey(): void
    {
        $provider = new GeminiProvider();
        $this->assertSame('gemini', $provider->getProviderKey());
    }

    public function testClassifyErrorReturnsAiProviderError(): void
    {
        $provider = new GeminiProvider();
        $error    = $provider->classifyError(new \RuntimeException('HTTP 401 unauthorized'));

        $this->assertInstanceOf(AiProviderError::class, $error);
        $this->assertSame(AiProviderError::CATEGORY_AUTHENTICATION, $error->category);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\Images\OpenAiImageProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Images\OpenAiImageProvider
 */
class OpenAiImageProviderTest extends CIUnitTestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = [
            'AI_OPENAI_API_KEY'      => $_ENV['AI_OPENAI_API_KEY'] ?? null,
            'AI_OPENAI_IMAGE_MODEL'  => $_ENV['AI_OPENAI_IMAGE_MODEL'] ?? null,
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
        unset($_ENV['AI_OPENAI_API_KEY']);

        $provider = new OpenAiImageProvider();
        $this->assertFalse($provider->isConfigured());
    }

    public function testIsConfiguredTrueWhenEnvSet(): void
    {
        $_ENV['AI_OPENAI_API_KEY'] = 'sk-test-key';

        $provider = new OpenAiImageProvider();
        $this->assertTrue($provider->isConfigured());
    }

    public function testFormatSize(): void
    {
        $provider = new OpenAiImageProvider();
        $this->assertSame('1536x1024', $provider->formatSize(1536, 1024));
    }

    public function testResolveModelKeyPrefersInputThenEnvThenDefault(): void
    {
        unset($_ENV['AI_OPENAI_IMAGE_MODEL']);
        $provider = new OpenAiImageProvider();
        $this->assertSame('gpt-image-1', $provider->resolveModelKey(''));

        $_ENV['AI_OPENAI_IMAGE_MODEL'] = 'dall-e-3';
        $provider = new OpenAiImageProvider();
        $this->assertSame('dall-e-3', $provider->resolveModelKey(''));
        $this->assertSame('custom', $provider->resolveModelKey('custom'));
    }
}

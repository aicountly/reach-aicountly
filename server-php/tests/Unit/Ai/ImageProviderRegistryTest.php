<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\Images\GeminiImageProvider;
use App\Libraries\Ai\Images\ImageProviderRegistry;
use App\Libraries\Ai\Images\OpenAiImageProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Images\ImageProviderRegistry
 */
class ImageProviderRegistryTest extends CIUnitTestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = [
            'AI_OPENAI_API_KEY'  => $_ENV['AI_OPENAI_API_KEY'] ?? null,
            'AI_GEMINI_API_KEY'  => $_ENV['AI_GEMINI_API_KEY'] ?? null,
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

    public function testAllKeysContainsBothProviders(): void
    {
        $registry = new ImageProviderRegistry();
        $keys     = $registry->allKeys();

        $this->assertContains(OpenAiImageProvider::PROVIDER_KEY, $keys);
        $this->assertContains(GeminiImageProvider::PROVIDER_KEY, $keys);
    }

    public function testGetThrowsWhenUnconfigured(): void
    {
        unset($_ENV['AI_OPENAI_API_KEY'], $_ENV['AI_GEMINI_API_KEY']);

        $registry = new ImageProviderRegistry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not configured');

        $registry->get(OpenAiImageProvider::PROVIDER_KEY);
    }
}

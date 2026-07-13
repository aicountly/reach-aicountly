<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Ai\Providers\MockAiProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\AiProviderRegistry
 */
class AiProviderRegistryTest extends CIUnitTestCase
{
    public function testMockProviderIsAlwaysAvailable(): void
    {
        $registry = new AiProviderRegistry();
        $provider = $registry->get(MockAiProvider::PROVIDER_KEY);

        $this->assertSame(MockAiProvider::PROVIDER_KEY, $provider->getProviderKey());
    }

    public function testThrowsForUnknownProvider(): void
    {
        $registry = new AiProviderRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $registry->get('nonexistent_provider');
    }

    public function testAllKeysContainsMock(): void
    {
        $registry = new AiProviderRegistry();
        $this->assertContains(MockAiProvider::PROVIDER_KEY, $registry->allKeys());
    }

    public function testRegisterOverridesProvider(): void
    {
        $registry = new AiProviderRegistry();
        $custom   = new MockAiProvider('success', ['key' => 'custom']);
        $registry->register($custom);

        $resolved = $registry->get(MockAiProvider::PROVIDER_KEY);
        $this->assertSame($custom, $resolved);
    }

    public function testUnconfiguredOpenAiThrowsWithoutEnvKey(): void
    {
        // Ensure key is absent
        $original = $_ENV['AI_OPENAI_API_KEY'] ?? null;
        unset($_ENV['AI_OPENAI_API_KEY']);

        $registry = new AiProviderRegistry();

        try {
            $registry->get('openai');
            $this->fail('Expected RuntimeException for unconfigured provider');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        } finally {
            if ($original !== null) {
                $_ENV['AI_OPENAI_API_KEY'] = $original;
            }
        }
    }
}

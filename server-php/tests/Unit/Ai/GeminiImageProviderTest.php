<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\Images\GeminiImageProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Images\GeminiImageProvider
 */
class GeminiImageProviderTest extends CIUnitTestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = [
            'AI_GEMINI_API_KEY'       => $_ENV['AI_GEMINI_API_KEY'] ?? null,
            'AI_GEMINI_IMAGE_MODEL'   => $_ENV['AI_GEMINI_IMAGE_MODEL'] ?? null,
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

        $provider = new GeminiImageProvider();
        $this->assertFalse($provider->isConfigured());
    }

    public function testIsConfiguredTrueWhenEnvSet(): void
    {
        $_ENV['AI_GEMINI_API_KEY'] = 'test-gemini-key';

        $provider = new GeminiImageProvider();
        $this->assertTrue($provider->isConfigured());
    }

    public function testFormatSize(): void
    {
        $provider = new GeminiImageProvider();
        $this->assertSame('1024x768', $provider->formatSize(1024, 768));
    }

    public function testResolveModelKeyPrefersInputThenEnvThenDefault(): void
    {
        unset($_ENV['AI_GEMINI_IMAGE_MODEL']);
        $provider = new GeminiImageProvider();
        $this->assertSame('gemini-2.5-flash-image', $provider->resolveModelKey(''));

        $_ENV['AI_GEMINI_IMAGE_MODEL'] = 'custom-image-model';
        $provider = new GeminiImageProvider();
        $this->assertSame('custom-image-model', $provider->resolveModelKey(''));
        $this->assertSame('override', $provider->resolveModelKey('override'));
    }
}

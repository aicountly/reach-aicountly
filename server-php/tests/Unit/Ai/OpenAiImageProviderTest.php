<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\OpenAiImageProvider;
use App\Libraries\Settings\ReachConfig;
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
        ReachConfig::flush();
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

    public function testResolveModelKeyPrefersInputThenSettingThenGptImage(): void
    {
        unset($_ENV['AI_OPENAI_IMAGE_MODEL']);
        $provider = new OpenAiImageProvider();
        $this->assertSame('gpt-image-2.5-flare', $provider->resolveModelKey(''), 'dall-e-* left the API on 12 May 2026');

        $_ENV['AI_OPENAI_IMAGE_MODEL'] = 'gpt-image-1';
        $provider = new OpenAiImageProvider();
        $this->assertSame('gpt-image-1', $provider->resolveModelKey(''));
        $this->assertSame('custom', $provider->resolveModelKey('custom'));
    }

    public function testDallE3BodyIsSingleHdBase64AtASupportedSize(): void
    {
        $body = (new OpenAiImageProvider())->requestBody('dall-e-3', new ImageGenerationInput(prompt: 'A ledger', width: 1536, height: 1024, count: 3));

        $this->assertSame(1, $body['n']);
        $this->assertSame('1792x1024', $body['size'], 'a 3:2 request snaps to the landscape size');
        $this->assertSame('b64_json', $body['response_format']);
        $this->assertSame('hd', $body['quality']);
    }

    public function testGptImageBodyHasNoResponseFormat(): void
    {
        $body = (new OpenAiImageProvider())->requestBody('gpt-image-1', new ImageGenerationInput(prompt: 'A ledger', width: 1792, height: 1024));

        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertSame('1536x1024', $body['size']);
        $this->assertSame('medium', $body['quality']);
        $this->assertSame('png', $body['output_format']);

        $_ENV['AI_OPENAI_IMAGE_QUALITY'] = 'high';
        $this->assertSame('high', (new OpenAiImageProvider())->requestBody('gpt-image-2.5-flare', new ImageGenerationInput(prompt: 'x', width: 1024, height: 1024))['quality']);
        $_ENV['AI_OPENAI_IMAGE_QUALITY'] = 'ultra';
        $this->assertSame('medium', (new OpenAiImageProvider())->requestBody('gpt-image-2.5-flare', new ImageGenerationInput(prompt: 'x', width: 1024, height: 1024))['quality'], 'an unknown quality falls back to medium');
        unset($_ENV['AI_OPENAI_IMAGE_QUALITY']);
    }
}

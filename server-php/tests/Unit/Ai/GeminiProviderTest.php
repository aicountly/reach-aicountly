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

    public function testGeminiSafeSchemaConvertsNullUnionsAndDropsDuplicateBodiesFromRequired(): void
    {
        $provider = new GeminiProvider();
        $method   = new \ReflectionMethod(GeminiProvider::class, 'geminiSafeSchema');
        $method->setAccessible(true);

        $schema = [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['title', 'body_html', 'body_markdown', 'body_plain_text', 'primary_cta'],
            'properties'           => [
                'meta_title'      => ['type' => 'string'],
                'title'           => ['type' => 'string'],
                'primary_cta'     => ['type' => ['string', 'null'], 'maxLength' => 80],
                'body_html'       => ['type' => 'string', 'minLength' => 400],
                'body_markdown'   => ['type' => 'string', 'minLength' => 300],
                'body_plain_text' => ['type' => 'string', 'minLength' => 300],
                'summary'         => ['type' => 'string'],
            ],
        ];

        /** @var array<string,mixed> $safe */
        $safe = $method->invoke($provider, $schema);

        $this->assertArrayNotHasKey('additionalProperties', $safe);
        $this->assertSame('string', $safe['properties']['primary_cta']['type']);
        $this->assertTrue($safe['properties']['primary_cta']['nullable']);
        $this->assertNotContains('body_markdown', $safe['required']);
        $this->assertNotContains('body_plain_text', $safe['required']);
        $this->assertContains('body_html', $safe['required']);
        $this->assertSame(
            ['title', 'summary', 'body_html', 'body_markdown', 'body_plain_text', 'meta_title', 'primary_cta'],
            $safe['propertyOrdering'],
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Libraries\Blog\Verification\CrossReviewRouter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Libraries\Blog\Verification\CrossReviewRouter
 */
final class CrossReviewRouterTest extends TestCase
{
    private CrossReviewRouter $router;

    protected function setUp(): void
    {
        $this->router = new CrossReviewRouter();
    }

    public function testOpenAiMapsToGemini(): void
    {
        $this->assertSame('gemini', $this->router->reviewerFor('openai'));
    }

    public function testGeminiMapsToOpenAi(): void
    {
        $this->assertSame('openai', $this->router->reviewerFor('gemini'));
    }

    public function testDefaultMapsToOpenAi(): void
    {
        $this->assertSame('openai', $this->router->reviewerFor('perplexity'));
    }

    public function testVerifierKeyIsPerplexity(): void
    {
        $this->assertSame('perplexity', $this->router->verifierKey());
    }

    public function testAssertNotSameProviderThrowsWhenEqual(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router->assertNotSameProvider('openai', 'openai');
    }

    public function testAssertNotSameProviderPassesWhenDifferent(): void
    {
        $this->router->assertNotSameProvider('openai', 'gemini');
        $this->assertTrue(true);
    }
}

<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Blog\BlogMetadataService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Blog\BlogMetadataService
 */
class BlogMetadataServiceTest extends CIUnitTestCase
{
    private BlogMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlogMetadataService();
    }

    public function testReadingTimeForShortBody(): void
    {
        $html = '<p>Short article.</p>';
        $this->assertSame(1, $this->service->estimateReadingTime($html));
    }

    public function testReadingTimeFor400Words(): void
    {
        $words = str_repeat('word ', 400);
        $html  = '<p>' . $words . '</p>';
        $time  = $this->service->estimateReadingTime($html);
        $this->assertSame(2, $time); // 400/200 = 2
    }

    public function testReadingTimeFor201Words(): void
    {
        $words = str_repeat('word ', 201);
        $html  = '<p>' . $words . '</p>';
        $time  = $this->service->estimateReadingTime($html);
        $this->assertSame(2, $time); // ceil(201/200) = 2
    }

    public function testReadingTimeMinIsOne(): void
    {
        $this->assertSame(1, $this->service->estimateReadingTime(''));
    }

    public function testDeriveExcerptReturnsPlainText(): void
    {
        $html    = '<h2>Introduction</h2><p>AICOUNTLY is an AI-powered accounting platform.</p>';
        $excerpt = $this->service->deriveExcerpt($html);
        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertStringContainsString('AICOUNTLY', $excerpt);
    }

    public function testDeriveExcerptRespectMaxLength(): void
    {
        $longText = str_repeat('This is a long sentence about compliance. ', 20);
        $html     = '<p>' . $longText . '</p>';
        $excerpt  = $this->service->deriveExcerpt($html, 100);
        $this->assertLessThanOrEqual(103, strlen($excerpt)); // 100 + ellipsis
    }

    public function testDeriveExcerptReturnsFullTextIfShort(): void
    {
        $html    = '<p>Short text.</p>';
        $excerpt = $this->service->deriveExcerpt($html);
        $this->assertSame('Short text.', $excerpt);
    }

    public function testDeriveExcerptEndsWithEllipsis(): void
    {
        $html    = '<p>' . str_repeat('word ', 100) . '</p>';
        $excerpt = $this->service->deriveExcerpt($html, 50);
        $this->assertStringEndsWith('…', $excerpt);
    }

    public function testReadingTimeStripsHtmlTags(): void
    {
        $htmlWords = str_repeat('<b>word</b> ', 200);
        $time      = $this->service->estimateReadingTime($htmlWords);
        $this->assertSame(1, $time); // 200 words = 1 minute
    }
}

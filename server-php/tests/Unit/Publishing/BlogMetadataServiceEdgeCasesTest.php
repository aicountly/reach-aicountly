<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Blog\BlogMetadataService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Edge-case and additional tests for BlogMetadataService.
 *
 * @covers \App\Libraries\Publishing\Blog\BlogMetadataService
 */
class BlogMetadataServiceEdgeCasesTest extends CIUnitTestCase
{
    private BlogMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlogMetadataService();
    }

    public function testEstimateReadingTimeExactly200Words(): void
    {
        $html = '<p>' . str_repeat('word ', 200) . '</p>';
        $this->assertSame(1, $this->service->estimateReadingTime($html));
    }

    public function testEstimateReadingTimeExactly201Words(): void
    {
        $html = '<p>' . str_repeat('word ', 201) . '</p>';
        $this->assertSame(2, $this->service->estimateReadingTime($html));
    }

    public function testEstimateReadingTimeExactly600Words(): void
    {
        $html = '<p>' . str_repeat('word ', 600) . '</p>';
        $this->assertSame(3, $this->service->estimateReadingTime($html));
    }

    public function testExcerptNeverEmptyForNonEmptyHtml(): void
    {
        $excerpt = $this->service->deriveExcerpt('<p>Hello world.</p>');
        $this->assertNotEmpty($excerpt);
    }

    public function testExcerptStripsTags(): void
    {
        $excerpt = $this->service->deriveExcerpt('<h1>Title</h1><p>Body <em>text</em></p>');
        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertStringNotContainsString('>', $excerpt);
    }

    public function testExcerptCollapsesMutlipleWhitespace(): void
    {
        $excerpt = $this->service->deriveExcerpt('<p>Hello   world</p>');
        $this->assertStringNotContainsString('  ', $excerpt);
    }

    public function testExcerptDefaultMaxLengthIs200(): void
    {
        $longText = str_repeat('word ', 200);
        $excerpt  = $this->service->deriveExcerpt('<p>' . $longText . '</p>');
        $this->assertLessThanOrEqual(203, strlen($excerpt)); // 200 + ellipsis
    }

    public function testExcerptCutsAtWordBoundary(): void
    {
        $text    = 'short words only here and then more text to see';
        $excerpt = $this->service->deriveExcerpt('<p>' . $text . '</p>', 20);
        // Should not end mid-word
        $this->assertStringEndsWith('…', $excerpt);
    }

    public function testEstimateReadingTimeIgnoresHtmlEntities(): void
    {
        $html = '<p>AICOUNTLY &amp; partners provide <strong>AI-powered</strong> solutions.</p>';
        $time = $this->service->estimateReadingTime($html);
        $this->assertSame(1, $time);
    }

    public function testExcerptFromEmptyStringIsEmpty(): void
    {
        $excerpt = $this->service->deriveExcerpt('');
        $this->assertSame('', $excerpt);
    }

    public function testLargeBodyReturnsPositiveReadingTime(): void
    {
        $words = str_repeat('accounting ', 2000);
        $html  = '<div>' . $words . '</div>';
        $this->assertGreaterThan(5, $this->service->estimateReadingTime($html));
    }
}

<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Blog\BlogMetadataService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for BlogMetadataService.
 *
 * @group publishing
 */
class BlogMetadataIntegrationTest extends CIUnitTestCase
{
    private BlogMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlogMetadataService();
    }

    public function testTypicalBlogPostReadingTime(): void
    {
        // ~800 word blog post = 4 minutes
        $html = '<article>' . str_repeat('<p>' . str_repeat('word ', 50) . '</p>', 16) . '</article>';
        $time = $this->service->estimateReadingTime($html);
        $this->assertSame(4, $time);
    }

    public function testExcerptFromRealBlogHtml(): void
    {
        $html = '<h1>How AICOUNTLY Transforms Accounting</h1>
            <p>AICOUNTLY is a cloud-based, AI-powered accounting platform designed specifically for Indian businesses.
            It automates repetitive bookkeeping tasks so your team can focus on growing your business.</p>
            <h2>Key Features</h2>
            <ul><li>Bank Reconciliation</li><li>GST Filing</li><li>TDS Management</li></ul>';

        $excerpt = $this->service->deriveExcerpt($html, 160);

        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertStringContainsString('AICOUNTLY', $excerpt);
        $this->assertLessThanOrEqual(163, strlen($excerpt));
    }

    public function testExcerptFromHeaderOnlyHtml(): void
    {
        $html    = '<h1>Title Only</h1>';
        $excerpt = $this->service->deriveExcerpt($html);
        $this->assertSame('Title Only', $excerpt);
    }

    public function testExcerptFromMixedStructuredHtml(): void
    {
        $html = '<section><h2>Overview</h2><p>Short intro.</p><ol><li>Item 1</li><li>Item 2</li></ol></section>';
        $excerpt = $this->service->deriveExcerpt($html);
        $this->assertNotEmpty($excerpt);
        $this->assertStringNotContainsString('<li>', $excerpt);
    }

    public function testReadingTimeScalesWithWordCount(): void
    {
        $short  = $this->service->estimateReadingTime('<p>' . str_repeat('w ', 100) . '</p>');
        $medium = $this->service->estimateReadingTime('<p>' . str_repeat('w ', 400) . '</p>');
        $long   = $this->service->estimateReadingTime('<p>' . str_repeat('w ', 1000) . '</p>');

        $this->assertGreaterThan($short, $medium);
        $this->assertGreaterThan($medium, $long);
        $this->assertSame(1, $short);
        $this->assertSame(2, $medium);
        $this->assertSame(5, $long);
    }

    public function testExcerptEndingAtWordBoundaryNoMidWordCut(): void
    {
        $text    = 'The quick brown fox jumps over the lazy dog and then runs away quickly.';
        $excerpt = $this->service->deriveExcerpt('<p>' . $text . '</p>', 30);

        if (str_ends_with($excerpt, '…')) {
            // Should not end mid-word (last char before ellipsis should be a word char)
            $beforeEllipsis = substr($excerpt, 0, -3); // remove utf-8 ellipsis (3 bytes)
            $this->assertMatchesRegularExpression('/[a-z]$/', $beforeEllipsis);
        }
    }
}

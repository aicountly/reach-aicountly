<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Blog\BlogMetadataService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Workflow integration tests for BlogMetadataService in real content scenarios.
 *
 * @group publishing
 */
class BlogMetadataServiceWorkflowTest extends CIUnitTestCase
{
    private BlogMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlogMetadataService();
    }

    public function testShortTipPostReadingTime(): void
    {
        // ~300 word tip post
        $html = '<article>
            <h1>Quick Tip: Set Up GST in 3 Steps</h1>
            <p>' . str_repeat('word ', 300) . '</p>
            </article>';
        $time = $this->service->estimateReadingTime($html);
        $this->assertSame(2, $time);
    }

    public function testLongCaseStudyReadingTime(): void
    {
        // ~1500 word case study
        $html = '<article>' . str_repeat('<p>' . str_repeat('word ', 100) . '</p>', 15) . '</article>';
        $time = $this->service->estimateReadingTime($html);
        $this->assertSame(8, $time); // 1500/200 = 7.5, ceil = 8
    }

    public function testExcerptForHelpArticle(): void
    {
        $html = '<article>
            <h2>Getting Started with Bank Reconciliation</h2>
            <p>Bank reconciliation in AICOUNTLY ensures your accounting records match your bank statements.
            This process helps detect discrepancies, prevent fraud, and maintain accurate financial records for your business.</p>
            </article>';

        $excerpt = $this->service->deriveExcerpt($html, 150);
        $this->assertStringContainsString('Bank reconciliation', $excerpt);
        $this->assertLessThanOrEqual(153, strlen($excerpt));
    }

    public function testExcerptDoesNotIncludeHtmlAttributes(): void
    {
        $html = '<p class="intro" style="font-size:16px"><strong>AICOUNTLY</strong> helps businesses.</p>';
        $excerpt = $this->service->deriveExcerpt($html);
        $this->assertStringNotContainsString('class=', $excerpt);
        $this->assertStringNotContainsString('style=', $excerpt);
        $this->assertStringContainsString('AICOUNTLY', $excerpt);
    }

    public function testExcerptForStructuredContent(): void
    {
        $html = '<section>
            <h2>Introduction</h2>
            <p>GST compliance is critical for Indian businesses. AICOUNTLY automates the entire GST workflow,
            from invoice generation to return filing, saving hours of manual work each month.</p>
            <h2>Key Features</h2>
            <ul>
                <li>Automated GSTR-1 generation</li>
                <li>GSTR-3B reconciliation</li>
                <li>E-invoice generation</li>
            </ul>
            </section>';

        $excerpt = $this->service->deriveExcerpt($html, 200);
        $this->assertStringContainsString('GST', $excerpt);
        $this->assertStringNotContainsString('<h2>', $excerpt);
        $this->assertStringNotContainsString('<ul>', $excerpt);
    }

    public function testReadingTimeConsistentAcrossFormats(): void
    {
        $words = str_repeat('accounting software ', 100); // 200 words

        $plain   = $this->service->estimateReadingTime('<p>' . $words . '</p>');
        $complex = $this->service->estimateReadingTime(
            '<article><section><div><p>' . $words . '</p></div></section></article>'
        );

        $this->assertSame($plain, $complex);
    }
}

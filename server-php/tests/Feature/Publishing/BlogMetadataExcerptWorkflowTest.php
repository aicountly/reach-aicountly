<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\Blog\BlogMetadataService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Workflow tests for excerpt generation in various blog post formats.
 *
 * @group publishing
 */
class BlogMetadataExcerptWorkflowTest extends CIUnitTestCase
{
    private BlogMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlogMetadataService();
    }

    public function testExcerptForAiAccountingBlogPost(): void
    {
        $html = '<article>
            <h1>How AI is Transforming Accounting for Indian SMBs</h1>
            <p>Artificial Intelligence is rapidly changing how businesses manage their finances.
            For Indian small and medium businesses, AI-powered accounting software like AICOUNTLY
            is making compliance easier, faster, and more accurate than ever before.</p>
            <h2>Key Benefits</h2>
            <p>From automated GST filing to intelligent bank reconciliation, AI removes the burden
            of manual data entry and reduces human error in financial records.</p>
            </article>';

        $excerpt = $this->service->deriveExcerpt($html, 200);
        $this->assertStringContainsString('Artificial Intelligence', $excerpt);
        $this->assertLessThanOrEqual(203, strlen($excerpt));
        $this->assertStringNotContainsString('<', $excerpt);
    }

    public function testExcerptForCaseStudyPost(): void
    {
        $html = '<div class="case-study">
            <p><strong>Company:</strong> Sharma Textiles Pvt Ltd</p>
            <p><strong>Challenge:</strong> Manual GST reconciliation taking 3 days per month.</p>
            <p><strong>Solution:</strong> AICOUNTLY automated the entire GST workflow,
            reducing reconciliation time from 3 days to 2 hours.</p>
            </div>';

        $excerpt = $this->service->deriveExcerpt($html, 150);
        $this->assertNotEmpty($excerpt);
        $this->assertStringNotContainsString('<strong>', $excerpt);
    }

    public function testExcerptFromSingleParagraph(): void
    {
        $html    = '<p>AICOUNTLY delivers AI-powered accounting solutions designed specifically for Indian businesses, helping them comply with GST, TDS, and other regulatory requirements.</p>';
        $excerpt = $this->service->deriveExcerpt($html, 100);

        $this->assertStringStartsWith('AICOUNTLY', $excerpt);
        $this->assertLessThanOrEqual(103, strlen($excerpt));
    }

    public function testExcerptFromComplexHtmlWithNestedElements(): void
    {
        $html = '<article>
            <header><h1>Title</h1></header>
            <section>
                <aside>Ad content</aside>
                <div class="content">
                    <p>Main article content about accounting automation and compliance in India.</p>
                </div>
            </section>
            </article>';

        $excerpt = $this->service->deriveExcerpt($html);
        $this->assertNotEmpty($excerpt);
        $this->assertStringNotContainsString('class=', $excerpt);
    }

    public function testShortContentDoesNotAddEllipsis(): void
    {
        $html    = '<p>Short text.</p>';
        $excerpt = $this->service->deriveExcerpt($html, 200);
        $this->assertSame('Short text.', $excerpt);
        $this->assertStringNotContainsString('…', $excerpt);
    }

    public function testLongContentAddsEllipsis(): void
    {
        $html = '<p>' . str_repeat('This is a very long sentence about accounting. ', 20) . '</p>';
        $excerpt = $this->service->deriveExcerpt($html, 100);
        $this->assertStringEndsWith('…', $excerpt);
    }
}

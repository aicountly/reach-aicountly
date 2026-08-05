<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Blog\BlogMetadataService;
use App\Libraries\Publishing\Blog\CoverAltTextBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Listing-card behaviour: what aicountly.com/blogs actually renders.
 *
 * @covers \App\Libraries\Publishing\Blog\BlogMetadataService
 * @covers \App\Libraries\Publishing\Blog\CoverAltTextBuilder
 */
class BlogListingExcerptTest extends CIUnitTestCase
{
    private BlogMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlogMetadataService();
    }

    public function testExcerptDoesNotFuseWordsAcrossBlockTags(): void
    {
        $html    = '<h2>Introduction</h2><p>Input tax credit offsets the GST paid on business purchases.</p>';
        $excerpt = $this->service->deriveExcerpt($html);

        $this->assertStringContainsString('Introduction Input', $excerpt);
        $this->assertStringNotContainsString('IntroductionInput', $excerpt);
    }

    public function testExcerptPrefersFirstSubstantiveParagraphOverHeading(): void
    {
        $html = '<h1>GST Input Tax Credit Explained for Small Businesses</h1>'
            . '<p>TL;DR</p>'
            . '<p>Input tax credit lets a registered business offset the GST paid on its purchases '
            . 'against the GST collected on its sales, provided the supplier has filed GSTR-1.</p>';

        $excerpt = $this->service->deriveExcerpt($html);

        $this->assertStringStartsWith('Input tax credit lets', $excerpt);
    }

    public function testExcerptStripsDecorativeDotRuns(): void
    {
        $html    = '<p>Untitled draft.........................................</p>';
        $excerpt = $this->service->deriveExcerpt($html);

        $this->assertStringNotContainsString('....', $excerpt);
    }

    public function testExcerptTruncationIsMultibyteSafe(): void
    {
        $html    = '<p>' . str_repeat('जीएसटी क्रेडिट ', 60) . '</p>';
        $excerpt = $this->service->deriveExcerpt($html, 80);

        $this->assertSame($excerpt, mb_convert_encoding($excerpt, 'UTF-8', 'UTF-8'));
        $this->assertLessThanOrEqual(81, mb_strlen($excerpt));
    }

    public function testExcerptDoesNotEndWithDanglingPunctuation(): void
    {
        $html    = '<p>Compliance, filing, reconciliation, and audit trails all matter for growing teams.</p>';
        $excerpt = $this->service->deriveExcerpt($html, 20);

        $this->assertStringEndsWith('…', $excerpt);
        $this->assertStringNotContainsString(',…', $excerpt);
    }

    public function testReadingTimeCountsNonAsciiWords(): void
    {
        $html = '<p>' . str_repeat('जीएसटी ', 400) . '</p>';

        $this->assertSame(2, $this->service->estimateReadingTime($html));
    }

    public function testCoverAltTextDescribesTheArticle(): void
    {
        $alt = CoverAltTextBuilder::build('TDS Compliance Basics for Growing Companies', 'Compliance');

        $this->assertStringContainsString('TDS Compliance Basics', $alt);
        $this->assertStringContainsString('compliance', $alt);
    }

    public function testCoverAltTextFallsBackWithoutTitle(): void
    {
        $this->assertNotSame('', CoverAltTextBuilder::build('', ''));
    }
}

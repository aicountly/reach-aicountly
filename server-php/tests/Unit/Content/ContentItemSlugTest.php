<?php

namespace Tests\Unit\Content;

use App\Models\Content\ContentItemModel;
use PHPUnit\Framework\TestCase;

/**
 * Slug generation is pure — no database needed.
 *
 * Regression cover for the filter-before-lowercase bug that ate the first
 * letter of every capitalised word and shipped the result as a public URL.
 */
final class ContentItemSlugTest extends TestCase
{
    public function testCapitalisedTitleKeepsEveryWord(): void
    {
        $this->assertSame(
            'tds-compliance-basics-for-growing-companies',
            ContentItemModel::slugifyTitle('TDS Compliance Basics for Growing Companies'),
        );
    }

    public function testAcronymSurvives(): void
    {
        $this->assertSame('gst-filing-guide', ContentItemModel::slugifyTitle('GST Filing Guide'));
    }

    public function testPunctuationCollapsesToSingleSeparator(): void
    {
        $this->assertSame(
            'tds-what-changed-in-2026',
            ContentItemModel::slugifyTitle('TDS: what changed in 2026?'),
        );
    }

    public function testLeadingAndTrailingSeparatorsAreTrimmed(): void
    {
        $this->assertSame('quarterly-update', ContentItemModel::slugifyTitle('  "Quarterly Update!"  '));
    }

    public function testAlreadyLowercaseTitleIsUnchanged(): void
    {
        $this->assertSame('simple-title', ContentItemModel::slugifyTitle('simple title'));
    }

    public function testUnslugifiableTitleFallsBackInsteadOfEmptyString(): void
    {
        // An empty slug would collide with any other empty slug on the unique
        // index and produce a bare "/" URL.
        $this->assertSame('content', ContentItemModel::slugifyTitle('———'));
    }
}

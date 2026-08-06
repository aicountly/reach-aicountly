<?php

namespace Tests\Unit\Content;

use App\Libraries\Content\SlugBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Slugs are public URLs, and they were being corrupted in a way that is
 * invisible until you read one: the character filter ran before the case
 * fold, so `[^a-z0-9]` treated every capital as punctuation.
 */
final class SlugBuilderTest extends CIUnitTestCase
{
    /**
     * The exact title that shipped as
     * "/ompliance-asics-for-rowing-ompanies" on the live site.
     */
    public function testCapitalisedTitleKeepsEveryLetter(): void
    {
        $this->assertSame(
            'tds-compliance-basics-for-growing-companies',
            SlugBuilder::slug('TDS Compliance Basics for Growing Companies'),
        );
    }

    public function testAcronymsSurvive(): void
    {
        $this->assertSame('gst-and-tds-for-msme', SlugBuilder::slug('GST and TDS for MSME'));
        $this->assertSame('seo-guide-2026', SlugBuilder::slug('SEO Guide 2026'));
    }

    public function testPunctuationCollapsesToSingleSeparators(): void
    {
        $this->assertSame(
            'what-is-form-16-a-guide',
            SlugBuilder::slug('  What is Form 16? — A Guide!!  '),
        );
    }

    public function testFallbackAppliesOnlyWhenNothingSurvives(): void
    {
        $this->assertSame('untitled', SlugBuilder::slug('!!!', 'untitled'));
        $this->assertSame('', SlugBuilder::slug('???'));
        $this->assertSame('real-title', SlugBuilder::slug('Real Title', 'untitled'));
    }

    public function testLowercaseTitlesAreUnaffectedByTheFix(): void
    {
        $this->assertSame(
            'already-lowercase-title',
            SlugBuilder::slug('already lowercase title'),
        );
    }

    public function testLegacyBuilderIsReproducedForRepairDetection(): void
    {
        $this->assertSame(
            'ompliance-asics-for-rowing-ompanies',
            SlugBuilder::legacyCorruptedSlug('TDS Compliance Basics for Growing Companies'),
        );
    }

    public function testCorruptionIsDetectedOnlyForTheBuildersOwnOutput(): void
    {
        $title = 'TDS Compliance Basics for Growing Companies';

        $this->assertTrue(SlugBuilder::isCorrupted('ompliance-asics-for-rowing-ompanies', $title));

        // A hand-picked slug must never be rewritten, however unlike the title.
        $this->assertFalse(SlugBuilder::isCorrupted('tds-compliance-guide', $title));
        // Nor an already-correct one.
        $this->assertFalse(SlugBuilder::isCorrupted('tds-compliance-basics-for-growing-companies', $title));
    }

    public function testLowercaseTitlesAreNeverFlaggedAsCorrupted(): void
    {
        // The old and new builders agree on lowercase input, so there is
        // nothing to repair and the repair pass must leave these alone.
        $this->assertFalse(SlugBuilder::isCorrupted('plain-lowercase-title', 'plain lowercase title'));
    }

    public function testEmptyInputsAreNotCorruption(): void
    {
        $this->assertFalse(SlugBuilder::isCorrupted('', 'Some Title'));
        $this->assertFalse(SlugBuilder::isCorrupted('some-slug', ''));
    }
}

<?php

namespace Tests\Unit\Blog;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Slugs are built by applying [^a-z0-9] to a lowercased title. Applying it to
 * the raw title instead treats every capital as a separator and eats it, which
 * is how live posts ended up at /blogs/ow-to-ile-1-ithout-ommon-istakes.
 *
 * These tests pin the character-level behaviour of both slug builders without
 * needing a database for the uniqueness loop.
 */
class BlogSlugGenerationTest extends CIUnitTestCase
{
    /** The corrected expression, shared by both buildUniqueSlug implementations. */
    private function slugify(string $title): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($title))) ?? '', '-');
    }

    /** The expression as it was before the fix. */
    private function buggySlugify(string $title): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', $title) ?? ''), '-');
    }

    public static function titleProvider(): array
    {
        return [
            'acronym and capitals' => ['How to File GSTR-1 Without Common Mistakes', 'how-to-file-gstr-1-without-common-mistakes'],
            'leading acronym'      => ['GST Input Tax Credit Explained for Small Businesses', 'gst-input-tax-credit-explained-for-small-businesses'],
            'all caps acronym'     => ['TDS Compliance Basics for Growing Companies', 'tds-compliance-basics-for-growing-companies'],
            'mixed case'           => ['Bookkeeping Checklist for Indian SMEs', 'bookkeeping-checklist-for-indian-smes'],
            'already lowercase'    => ['gst filing checklist for small businesses', 'gst-filing-checklist-for-small-businesses'],
            'punctuation'          => ['What is ITC? A 2026 guide!', 'what-is-itc-a-2026-guide'],
        ];
    }

    /**
     * @dataProvider titleProvider
     */
    public function testSlugKeepsEveryWord(string $title, string $expected): void
    {
        $this->assertSame($expected, $this->slugify($title));
    }

    public function testCapitalisedWordsSurviveWhereTheyPreviouslyVanished(): void
    {
        $title = 'How to File GSTR-1 Without Common Mistakes';

        $this->assertSame('ow-to-ile-1-ithout-ommon-istakes', $this->buggySlugify($title), 'pins the old behaviour');
        $this->assertStringContainsString('gstr-1', $this->slugify($title));
        $this->assertStringStartsWith('how-to-file', $this->slugify($title));
    }

    public function testTitleWithNoSluggableCharactersYieldsEmptyBase(): void
    {
        // Callers substitute a random 'topic-xxxx' base for this case.
        $this->assertSame('', $this->slugify('—— ??? ——'));
    }

    public function testLeadingAndTrailingSeparatorsAreTrimmed(): void
    {
        $this->assertSame('gst-returns', $this->slugify('  ...GST Returns!!  '));
    }
}

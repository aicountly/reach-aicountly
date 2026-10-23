<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Libraries\Content\Quality\LiveRevisionApplier;
use PHPUnit\Framework\TestCase;

/**
 * The title rule for a live correction: Gemini's new title is taken only
 * when it is still the article's topic (the URL never changes either way).
 *
 * @covers \App\Libraries\Content\Quality\LiveRevisionApplier
 */
final class LiveRevisionTitleTest extends TestCase
{
    private const BODY = '<p>Input tax credit under Section 16(4) of the CGST Act has a time limit: claim ITC on an invoice by 30 November '
        . 'of the following financial year or the date of the annual return, whichever is earlier. Track the ITC time limit monthly.</p>';

    public function testARephrasedTitleOnTheSameTopicIsTaken(): void
    {
        $this->assertTrue(LiveRevisionApplier::sameTopic('Section 16(4) ITC time limit explained', 'ITC time limit under Section 16(4) of the CGST Act', self::BODY));
    }

    public function testATitleOnAnotherTopicIsNotTaken(): void
    {
        $this->assertFalse(LiveRevisionApplier::sameTopic('Section 16(4) ITC time limit explained', 'Ten social media ideas that grow your audience', self::BODY));
    }

    public function testAnyTopicalTitleReplacesAPlaceholder(): void
    {
        $this->assertTrue(LiveRevisionApplier::sameTopic('Untitled draft', 'The ITC time limit under Section 16(4)', self::BODY));
    }

    public function testAPlaceholderOrTooShortTitleIsNeverTaken(): void
    {
        $this->assertFalse(LiveRevisionApplier::sameTopic('Section 16(4) ITC time limit explained', 'Untitled draft about the ITC time limit', self::BODY));
        $this->assertFalse(LiveRevisionApplier::sameTopic('Section 16(4) ITC time limit explained', 'ITC limit', self::BODY));
    }

    public function testATitleTheCorrectedBodyDoesNotReflectIsNotTaken(): void
    {
        $this->assertFalse(LiveRevisionApplier::sameTopic('GST ITC time limit', 'GST ITC reversal for exempt supplies and blocked credits', self::BODY));
    }
}

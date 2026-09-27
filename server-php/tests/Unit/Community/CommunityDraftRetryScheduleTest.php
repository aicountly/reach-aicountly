<?php

declare(strict_types=1);

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityOperationalAgentService;
use PHPUnit\Framework\TestCase;

/**
 * How often, and for how long, community:agents-run retries a first draft
 * whose AI generation failed.
 *
 * Before this schedule there was no retry at all: one failed generation left
 * the question holding an empty answer that nothing would ever pick up again.
 * The end-to-end retry is covered by Feature\Community\CommunityAgentRetryTest;
 * this pins the arithmetic, so changing it is a decision, not a side effect.
 *
 * @internal
 */
final class CommunityDraftRetryScheduleTest extends TestCase
{
    /** Doubling from one agents-run tick (30 minutes). */
    public function testBackoffDoublesFromThirtyMinutes(): void
    {
        $this->assertSame(1800,  CommunityOperationalAgentService::draftRetryBackoffSeconds(1));
        $this->assertSame(3600,  CommunityOperationalAgentService::draftRetryBackoffSeconds(2));
        $this->assertSame(7200,  CommunityOperationalAgentService::draftRetryBackoffSeconds(3));
        $this->assertSame(14400, CommunityOperationalAgentService::draftRetryBackoffSeconds(4));
        $this->assertSame(28800, CommunityOperationalAgentService::draftRetryBackoffSeconds(5));
    }

    /** Unbounded doubling would put a retry days out; the cap keeps it to twelve hours. */
    public function testBackoffIsCappedAtTwelveHours(): void
    {
        foreach ([6, 10, 64] as $failed) {
            $this->assertSame(43200, CommunityOperationalAgentService::draftRetryBackoffSeconds($failed));
        }
    }

    /**
     * Six attempts, so five waits: 0.5 + 1 + 2 + 4 + 8 hours. That is how long
     * a question keeps being retried before it waits on a human.
     */
    public function testTheWholeBudgetSpansFifteenAndAHalfHours(): void
    {
        $this->assertSame(6, CommunityOperationalAgentService::MAX_AUTOMATIC_DRAFT_ATTEMPTS);

        $waits = 0;
        for ($failed = 1; $failed < CommunityOperationalAgentService::MAX_AUTOMATIC_DRAFT_ATTEMPTS; $failed++) {
            $waits += CommunityOperationalAgentService::draftRetryBackoffSeconds($failed);
        }

        $this->assertSame(55800, $waits);
    }

    public function testAFirstDraftIsAlwaysDue(): void
    {
        $this->assertTrue(CommunityOperationalAgentService::isDraftRetryDue(0, 0));
    }

    public function testARetryWaitsOutItsBackoff(): void
    {
        $this->assertFalse(CommunityOperationalAgentService::isDraftRetryDue(1, 1799));
        $this->assertTrue(CommunityOperationalAgentService::isDraftRetryDue(1, 1800));

        $this->assertFalse(CommunityOperationalAgentService::isDraftRetryDue(3, 7199));
        $this->assertTrue(CommunityOperationalAgentService::isDraftRetryDue(3, 7200));
    }

    public function testRetriesStopOnceTheBudgetIsSpent(): void
    {
        $max = CommunityOperationalAgentService::MAX_AUTOMATIC_DRAFT_ATTEMPTS;

        $this->assertTrue(CommunityOperationalAgentService::isDraftRetryDue($max - 1, 86400));
        $this->assertFalse(CommunityOperationalAgentService::isDraftRetryDue($max, PHP_INT_MAX));
        $this->assertFalse(CommunityOperationalAgentService::isDraftRetryDue($max + 3, PHP_INT_MAX));
    }

    /** Defensive: a zero or negative count must not produce a zero wait. */
    public function testNonPositiveCountsStillWaitOneTick(): void
    {
        $this->assertSame(1800, CommunityOperationalAgentService::draftRetryBackoffSeconds(0));
        $this->assertSame(1800, CommunityOperationalAgentService::draftRetryBackoffSeconds(-2));
    }
}

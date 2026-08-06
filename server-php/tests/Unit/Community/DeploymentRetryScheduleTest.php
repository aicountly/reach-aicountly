<?php

declare(strict_types=1);

namespace Tests\Unit\Community;

use App\Libraries\Community\OfficialAnswerPublishingService;
use PHPUnit\Framework\TestCase;

/**
 * The retry cadence for failed community deployments.
 *
 * Scope, stated honestly: this covers the schedule arithmetic, not the
 * end-to-end retry-to-dead-letter flow. That flow's two real defects — the
 * status CHECK constraint rejecting 'dead_letter', and max_attempts
 * defaulting to 3 while the service assumed 5 — are fixed by migration and
 * verified directly against the production schema. What remains untested is
 * the orchestration between them, which needs a database.
 *
 * @internal
 */
final class DeploymentRetryScheduleTest extends TestCase
{
    /**
     * Doubling from 30s. The sweep will not re-pick a deployment until
     * next_retry_at has passed, so this is what decides how long a stuck
     * publication waits — and how hard a already-failing public site gets hit.
     */
    public function testBackoffDoublesFromThirtySeconds(): void
    {
        $this->assertSame(30,  OfficialAnswerPublishingService::backoffSeconds(1));
        $this->assertSame(60,  OfficialAnswerPublishingService::backoffSeconds(2));
        $this->assertSame(120, OfficialAnswerPublishingService::backoffSeconds(3));
        $this->assertSame(240, OfficialAnswerPublishingService::backoffSeconds(4));
        $this->assertSame(480, OfficialAnswerPublishingService::backoffSeconds(5));
    }

    /**
     * A full run of the default five attempts takes 30+60+120+240+480 = 930s,
     * about fifteen and a half minutes. Pinned exactly: this is how long an
     * operator waits before a stuck publication reaches dead_letter, so a
     * change to the schedule should be a decision rather than a side effect.
     */
    public function testFiveAttemptsSpanJustOverFifteenMinutes(): void
    {
        $delays = [];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $delays[] = OfficialAnswerPublishingService::backoffSeconds($attempt);
        }

        $this->assertSame([30, 60, 120, 240, 480], $delays);
        $this->assertSame(930, array_sum($delays));
    }

    /** Unbounded doubling would put a retry days out; the cap keeps it to an hour. */
    public function testBackoffIsCappedAtOneHour(): void
    {
        foreach ([8, 12, 20, 64] as $attempt) {
            $this->assertSame(3600, OfficialAnswerPublishingService::backoffSeconds($attempt));
        }
    }

    /** Defensive: a zero or negative attempt must not produce a zero delay. */
    public function testNonPositiveAttemptsStillWait(): void
    {
        $this->assertSame(30, OfficialAnswerPublishingService::backoffSeconds(0));
        $this->assertSame(30, OfficialAnswerPublishingService::backoffSeconds(-5));
    }
}

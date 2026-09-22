<?php

namespace Tests\Unit;

use App\Libraries\TrafficAnalyticsService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the KPI comparison-period math — deliberately skip
 * CIUnitTestCase (see UrlPolicyTest) since neither method touches the DB,
 * HTTP, or GA4.
 *
 * @internal
 */
final class TrafficAnalyticsServiceDateRangeTest extends TestCase
{
    public function testPreviousPeriodIsSameLengthAndImmediatelyBeforeCurrent(): void
    {
        // Mirrors fetchOverviewForStream's own "current period" math so this
        // test fails if the two ever drift apart.
        $days      = 30;
        $reference = '2026-09-22';
        $currentStart = date('Y-m-d', strtotime($reference . " -{$days} days"));

        [$previousStart, $previousEnd] = TrafficAnalyticsService::previousPeriodDates($days, $reference);

        $this->assertSame('2026-07-23', $previousStart);
        $this->assertSame('2026-08-22', $previousEnd);

        // No gap and no overlap: previous ends exactly one day before current starts.
        $this->assertSame(
            $previousEnd,
            date('Y-m-d', strtotime($currentStart . ' -1 day')),
        );

        // Same number of calendar days as the current window.
        $currentSpanDays  = (strtotime($reference) - strtotime($currentStart)) / 86400;
        $previousSpanDays = (strtotime($previousEnd) - strtotime($previousStart)) / 86400;
        $this->assertSame($currentSpanDays, $previousSpanDays);
    }

    public function testPreviousPeriodScalesWithEachDaysOption(): void
    {
        foreach ([7, 30, 90] as $days) {
            [$previousStart, $previousEnd] = TrafficAnalyticsService::previousPeriodDates($days, '2026-09-22');
            $spanDays = (strtotime($previousEnd) - strtotime($previousStart)) / 86400;

            $this->assertSame($days, (int) $spanDays, "days={$days}");
        }
    }

    public function testBuildComparisonComputesPercentChange(): void
    {
        $current  = ['sessions' => 1747, 'users' => 650, 'pageviews' => 1257, 'new_users' => 400, 'bounce_rate' => 63.4];
        $previous = ['sessions' => 1400, 'users' => 500, 'pageviews' => 1257, 'new_users' => 0, 'bounce_rate' => 70.0];

        $comparison = TrafficAnalyticsService::buildComparison($current, $previous);

        $this->assertSame(24.8, $comparison['sessions']['delta_pct']);
        $this->assertSame(347.0, $comparison['sessions']['delta']);
        $this->assertSame(30.0, $comparison['users']['delta_pct']);
        $this->assertSame(0.0, $comparison['pageviews']['delta_pct']);
        $this->assertSame(-9.4, $comparison['bounce_rate']['delta_pct']);
    }

    public function testBuildComparisonMarksMetricsWithNoPriorBaselineAsNull(): void
    {
        $comparison = TrafficAnalyticsService::buildComparison(
            ['sessions' => 42],
            ['sessions' => 0],
        );

        $this->assertNull($comparison['sessions']['delta_pct']);
        $this->assertSame(42.0, $comparison['sessions']['delta']);
    }

    public function testBuildComparisonIsZeroWhenBothPeriodsAreEmpty(): void
    {
        $comparison = TrafficAnalyticsService::buildComparison(
            ['sessions' => 0],
            ['sessions' => 0],
        );

        $this->assertSame(0.0, $comparison['sessions']['delta_pct']);
        $this->assertSame(0.0, $comparison['sessions']['delta']);
    }
}

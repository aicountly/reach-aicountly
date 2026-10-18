<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Commands\ReachSchedule;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * reach:schedule runs every minute. Before this, the 08:00 approval digest and
 * every hourly job were enqueued without an idempotency key, i.e. sixty times
 * an hour. Each planned job must carry a key scoped to its period.
 */
final class ReachScheduleTimetableTest extends CIUnitTestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function byType(string $utc): array
    {
        $out = [];
        foreach (ReachSchedule::plannedJobs(new \DateTimeImmutable($utc, new \DateTimeZone('UTC'))) as $job) {
            $out[$job['type']] = $job;
        }

        return $out;
    }

    public function testEveryPlannedJobCarriesAnIdempotencyKey(): void
    {
        for ($h = 0; $h < 24; $h++) {
            foreach ([0, 30] as $m) {
                foreach (ReachSchedule::plannedJobs(new \DateTimeImmutable(sprintf('2026-10-17 %02d:%02d:00', $h, $m), new \DateTimeZone('UTC'))) as $job) {
                    $this->assertNotEmpty($job['opts']['idempotency_key'] ?? null, $job['type'] . ' at ' . $h . ':' . $m . ' has no key');
                }
            }
        }
    }

    public function testApprovalDigestKeyIsPerDayAndOnTheNotificationsQueue(): void
    {
        $a = self::byType('2026-10-17 08:01:00')['reach.daily_approval_digest'];
        $b = self::byType('2026-10-17 08:59:00')['reach.daily_approval_digest'];

        $this->assertSame('approval-digest-2026-10-17', $a['opts']['idempotency_key']);
        $this->assertSame($a['opts']['idempotency_key'], $b['opts']['idempotency_key']);
        $this->assertSame('notifications', $a['opts']['queue']);
    }

    public function testHourlyKeysChangeEachHourOnly(): void
    {
        $k1 = self::byType('2026-10-17 10:05:00')['reach.content_due_date_reminder']['opts']['idempotency_key'];
        $k2 = self::byType('2026-10-17 10:55:00')['reach.content_due_date_reminder']['opts']['idempotency_key'];
        $k3 = self::byType('2026-10-17 11:05:00')['reach.content_due_date_reminder']['opts']['idempotency_key'];

        $this->assertSame($k1, $k2);
        $this->assertNotSame($k1, $k3);
    }

    public function testDailyReportRunsAt0800IstWithAnIstDateKey(): void
    {
        // 02:30 UTC = 08:00 IST
        $jobs = self::byType('2026-10-17 02:30:00');
        $this->assertArrayHasKey('reach.daily_analytics_report', $jobs);
        $this->assertSame('daily-report-2026-10-17', $jobs['reach.daily_analytics_report']['opts']['idempotency_key']);
        $this->assertSame(['date' => '2026-10-17'], $jobs['reach.daily_analytics_report']['payload']);
        $this->assertSame('notifications', $jobs['reach.daily_analytics_report']['opts']['queue']);

        // 02:29 UTC = 07:59 IST — not yet
        $this->assertArrayNotHasKey('reach.daily_analytics_report', self::byType('2026-10-17 02:29:00'));
    }

    public function testSeoTrackerRunsAt0600Ist(): void
    {
        $jobs = self::byType('2026-10-17 00:45:00'); // 06:15 IST
        $this->assertSame('seo-tracker-2026-10-17', $jobs['reach.seo_tracker']['opts']['idempotency_key']);
        $this->assertArrayNotHasKey('reach.seo_tracker', self::byType('2026-10-17 01:45:00'));
    }

    public function testSearchConsoleIngestStaysAt0400Utc(): void
    {
        $this->assertArrayHasKey('reach.search_console_ingest', self::byType('2026-10-17 04:10:00'));
        $this->assertArrayHasKey('reach.seo_snapshot', self::byType('2026-10-17 05:10:00'));
    }

    public function testEveryPlannedJobHasAHandler(): void
    {
        $registry = new \App\Libraries\JobHandlerRegistry();
        for ($h = 0; $h < 24; $h++) {
            foreach (ReachSchedule::plannedJobs(new \DateTimeImmutable(sprintf('2026-10-17 %02d:30:00', $h), new \DateTimeZone('UTC'))) as $job) {
                $this->assertTrue($registry->has($job['type']), $job['type'] . ' is scheduled but has no handler');
            }
        }
    }

    public function testStreamFSettingsAreRegistered(): void
    {
        $registry = \App\Libraries\Settings\ReachConfig::registry();
        foreach (['seo.tracker.enabled', 'seo.tracker.max_keywords_per_day', 'seo.tracker.location_code', 'seo.tracker.language_code',
                  'seo.tracker.depth', 'seo.tracker.our_domain', 'reports.daily.enabled', 'reports.daily.recipients'] as $key) {
            $this->assertArrayHasKey($key, $registry);
        }
        $this->assertSame(2356, $registry['seo.tracker.location_code']['default']);
    }

    public function testWorkerDocDrainsTheNotificationsQueue(): void
    {
        $doc = (string) file_get_contents(ROOTPATH . '../docs/operations/REACH_WORKER_AND_CRON.md');
        $this->assertMatchesRegularExpression('/reach:work --queue=[a-z,]*notifications/', $doc);
        $this->assertStringNotContainsString('--queue=default,blog,publishing,community --once', $doc);
    }

    public function testRoutineAndStructuredSweepsArePlannedOnTheirMinutes(): void
    {
        // Regression: these two used an undefined $svc inside the static
        // planner, so every tick on a multiple of 5 minutes threw and
        // reach:schedule stopped before the growth-intel jobs and heartbeat.
        $at15 = self::byType('2026-10-17 10:15:00');
        $this->assertSame('routine-content-sweep-202610171015', $at15['reach.routine_content_sweep']['opts']['idempotency_key']);
        $this->assertSame('default', $at15['reach.routine_content_sweep']['opts']['queue']);
        $this->assertSame('structured-content-sweep-202610171015', $at15['reach.structured_content_sweep']['opts']['idempotency_key']);
        $this->assertSame('publishing', $at15['reach.structured_content_sweep']['opts']['queue']);

        $at10 = self::byType('2026-10-17 10:10:00');
        $this->assertArrayHasKey('reach.routine_content_sweep', $at10);
        $this->assertArrayNotHasKey('reach.structured_content_sweep', $at10);

        $at11 = self::byType('2026-10-17 10:11:00');
        $this->assertArrayNotHasKey('reach.routine_content_sweep', $at11);
    }

    public function testSocialApiPostsArePolledEveryFiveMinutesOnTheDistributionQueue(): void
    {
        $at20 = self::byType('2026-10-17 10:20:00');
        $this->assertSame('social-post-poll-202610171020', $at20['reach.social_post_poll']['opts']['idempotency_key']);
        $this->assertSame('distribution', $at20['reach.social_post_poll']['opts']['queue']);
        $this->assertArrayNotHasKey('reach.social_post_poll', self::byType('2026-10-17 10:21:00'));
        $this->assertTrue((new \App\Libraries\JobHandlerRegistry())->has('reach.social_post_poll'));
    }
}

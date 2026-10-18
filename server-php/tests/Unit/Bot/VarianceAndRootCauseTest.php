<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Commands\ReachSchedule;
use App\Libraries\Bot\Plan\PerformanceService;
use App\Libraries\Bot\Plan\PlanBriefing;
use App\Libraries\Bot\Plan\PlanMetrics;
use App\Libraries\Bot\Plan\PlanPeriods;
use App\Libraries\Bot\Plan\PlanReportSection;
use App\Libraries\Bot\Plan\RootCauseClassifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Plan vs achieved per metric (achievement %, status, streaks), the top
 * lags, and root causes classified from real signals.
 */
final class VarianceAndRootCauseTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private static function signals(array $o = []): array
    {
        return array_replace_recursive([
            'window'       => ['from' => '2026-10-11', 'to' => '2026-10-17', 'days' => 7],
            'config'       => [
                'blog' => ['enabled' => true, 'daily_limit' => 6, 'auto_publish' => true, 'ai_cover' => true],
                'knowledge_base' => ['enabled' => true, 'daily_limit' => 10, 'auto_publish' => true],
                'video' => ['enabled' => true, 'vlogs' => 1, 'shorts' => 1, 'youtube_publisher' => 'social_api'],
                'seo' => ['enabled' => true], 'community' => ['enabled' => true], 'release_note' => ['enabled' => true], 'workflow_guide' => ['enabled' => true],
            ],
            'integrations' => ['gemini' => true, 'openai' => true, 'shotstack' => true, 'media_signing' => true, 'social_api' => true, 'dataforseo' => true,
                'search_console' => true, 'email' => true, 'engage_push' => true, 'engage_inbound' => true],
            'routine'      => ['blog' => ['submitted' => 10, 'rejected' => 0, 'stuck_vetting' => 0, 'stuck_cover' => 0, 'publish_failed' => 0, 'publish_blocked' => 0,
                'last_submission_at' => '2026-10-17 10:00:00', 'last_error' => null, 'quota_days' => 0, 'daily_limit' => 6]],
            'community' => [], 'structured' => [], 'video' => [], 'social' => [], 'vetting' => [], 'jobs' => [], 'topic_queue' => ['available' => 5],
        ], $o);
    }

    /** @param list<array{0:?float,1:?float}> $days target, achieved per day */
    private static function row(string $metric, array $days): array
    {
        $dates = PlanPeriods::dates('2026-10-11', PlanPeriods::addDays('2026-10-11', count($days) - 1));
        $plans = [];
        $actual = [];
        foreach ($dates as $i => $d) {
            [$t, $a] = $days[$i];
            if ($t !== null) {
                $plans[$d][$metric] = ['status' => 'target', 'target_value' => $t, 'base_target' => $t, 'source' => 'config'];
            }
            $actual[$d] = $a;
        }

        return PerformanceService::metricRow($metric, PlanMetrics::get($metric), $dates, $plans, $actual);
    }

    public function testAchievementStatusAndStreaks(): void
    {
        $lag = self::row('content.blog.published', [[6, 6], [6, 6], [6, 2], [6, 1], [6, 0]]);
        $this->assertSame(30.0, $lag['target']);
        $this->assertSame(15.0, $lag['achieved']);
        $this->assertSame(50, $lag['achievement_pct']);
        $this->assertSame('lagging', $lag['status']);
        $this->assertSame(['type' => 'lag', 'days' => 3], $lag['streak']);

        $met = self::row('content.blog.published', [[6, 2], [6, 6], [6, 7]]);
        $this->assertSame(['type' => 'hit', 'days' => 2], $met['streak']);
        $this->assertSame('behind', $met['status'], '15 of 18 = 83 %.');

        $noPlan = self::row('content.blog.published', [[null, 3], [null, 4]]);
        $this->assertSame('no_plan', $noPlan['status']);
        $this->assertNull($noPlan['target']);
        $this->assertSame(7.0, $noPlan['achieved'], 'What happened is still shown.');
    }

    public function testSearchDaysGoogleHasNotReportedCountForNeitherSide(): void
    {
        $r = self::row('search.clicks', [[100, 90], [100, 110], [100, null], [100, null]]);
        $this->assertSame(200.0, $r['target']);
        $this->assertSame(200.0, $r['achieved']);
        $this->assertSame(2, $r['days_without_data']);
        $this->assertSame('met', $r['status']);

        $none = self::row('search.clicks', [[100, null]]);
        $this->assertSame('no_data', $none['status']);
    }

    public function testMissingIntegrationIsTheStructuralCauseAndNamesTheFix(): void
    {
        $causes = RootCauseClassifier::classify('video.vlog.published', self::row('video.vlog.published', [[1, 0], [1, 0], [1, 0]]),
            self::signals(['integrations' => ['shotstack' => false], 'video' => ['vlog' => ['submitted' => 3, 'rejected' => 0, 'publish_failed' => 3, 'in_progress' => 0]]]));

        $this->assertSame('integration_missing', $causes[0]['code']);
        $this->assertStringContainsString('Shotstack', $causes[0]['label']);
        $this->assertTrue($causes[0]['owner_action']);
        $this->assertStringContainsString('Settings → AI & Automation → Video', $causes[0]['fix']);
        $this->assertContains('publish_failures', array_column($causes, 'code'));
        $this->assertTrue(RootCauseClassifier::isStructural($causes));
    }

    public function testVettingRejectionsCarryTheirReasons(): void
    {
        $causes = RootCauseClassifier::classify('content.blog.published', self::row('content.blog.published', [[6, 2]]), self::signals([
            'routine' => ['blog' => ['submitted' => 8, 'rejected' => 5]],
            'vetting' => ['blog' => ['total' => 8, 'approve' => 3, 'reject' => 5, 'top_reasons' => [['reason' => 'Unverified GST rate claim', 'count' => 3]]]],
        ]));
        $this->assertSame('vetting_rejections', $causes[0]['code']);
        $this->assertStringContainsString('Unverified GST rate claim (×3)', $causes[0]['detail']);
        $this->assertFalse($causes[0]['owner_action'], 'The bot handles this through the briefing.');
    }

    public function testRoutineInactivityStuckItemsJobsAndFallback(): void
    {
        $inactive = RootCauseClassifier::classify('content.knowledge_base.published', self::row('content.knowledge_base.published', [[10, 0]]), self::signals([
            'routine' => ['knowledge_base' => ['submitted' => 0, 'rejected' => 0, 'last_submission_at' => null]],
        ]));
        $this->assertSame('routine_inactive', $inactive[0]['code']);
        $this->assertStringContainsString('reach-kb', $inactive[0]['fix']);

        $stuck = RootCauseClassifier::classify('content.blog.published', self::row('content.blog.published', [[6, 3]]), self::signals([
            'routine' => ['blog' => ['stuck_vetting' => 2, 'last_error' => 'Vetting unavailable: HTTP 503']],
            'jobs' => ['reach.routine_content_sweep' => ['failed' => 2, 'dead_letter' => 1, 'last_error' => 'timeout']],
        ]));
        $codes = array_column($stuck, 'code');
        $this->assertSame(['pipeline_stuck', 'job_failures'], $codes);
        $this->assertStringContainsString('HTTP 503', $stuck[0]['detail']);

        $none = RootCauseClassifier::classify('seo.checks_run', self::row('seo.checks_run', [[35, 20]]), self::signals());
        $this->assertSame('unexplained', $none[0]['code']);

        $off = RootCauseClassifier::classify('content.blog.published', self::row('content.blog.published', [[6, 0]]), self::signals(['config' => ['blog' => ['enabled' => false]]]));
        $this->assertSame('automation_off', $off[0]['code']);
    }

    public function testOutcomesPointAtLaggingOutputUpstream(): void
    {
        $causes = RootCauseClassifier::classify('search.clicks', self::row('search.clicks', [[100, 50]]), self::signals(), ['content.blog.published']);
        $this->assertSame('upstream_output', $causes[0]['code']);
        $this->assertStringContainsString('Blog posts published', $causes[0]['detail']);
    }

    public function testTopLagsPutOutputsFirstAndRecommendationsAreOwnerOnly(): void
    {
        $rows = [
            'search.clicks'          => self::row('search.clicks', [[100, 10]]),
            'content.blog.published' => self::row('content.blog.published', [[6, 3]]),
            'video.vlog.published'   => self::row('video.vlog.published', [[1, 0]]),
            'seo.checks_run'         => self::row('seo.checks_run', [[35, 35]]),
        ];
        $sig = self::signals(['integrations' => ['shotstack' => false]]);
        foreach ($rows as $k => $r) {
            if ($r['status'] === 'lagging') {
                $rows[$k]['causes'] = RootCauseClassifier::classify($k, $r, $sig);
            }
        }
        $top = PerformanceService::topLags($rows);
        $this->assertSame(['video.vlog.published', 'content.blog.published', 'search.clicks'], array_column($top, 'metric_key'));

        $recs = PerformanceService::recommendations($rows, $sig);
        $this->assertNotEmpty($recs);
        $this->assertSame('high', $recs[0]['severity']);
        $this->assertStringContainsString('Shotstack', $recs[0]['action']);
    }

    public function testRaiseLimitIsOnlySuggestedWhenTheCapIsTheConstraint(): void
    {
        $rows = ['content.blog.published' => self::row('content.blog.published', array_fill(0, 7, [6, 6]))];
        $sig  = self::signals(['routine' => ['blog' => ['quota_days' => 6]], 'vetting' => ['blog' => ['total' => 10, 'approve' => 9]]]);
        $recs = PerformanceService::recommendations($rows, $sig);
        $this->assertCount(1, $recs);
        $this->assertStringContainsString('Consider raising automation.blog.daily_limit', $recs[0]['action']);
        $this->assertStringContainsString('Search Console', $recs[0]['action']);

        $this->assertSame([], PerformanceService::recommendations($rows, self::signals(['routine' => ['blog' => ['quota_days' => 2]]])));
    }

    public function testDailyReportSectionRendersHtmlAndText(): void
    {
        $s = ['configured' => true, 'date' => '2026-10-17',
            'metrics' => [['metric_key' => 'content.blog.published', 'label' => 'Blog posts published', 'kind' => 'output', 'target' => 6, 'achieved' => 3, 'achievement_pct' => 50, 'status' => 'lagging']],
            'top_lags' => [['label' => 'Blog posts published', 'achievement_pct' => 50, 'cause' => 'Vetting rejections']],
            'self_tune' => ['enabled' => true, 'applied' => 2]];
        $html = PlanReportSection::html($s);
        $this->assertStringContainsString('Blog posts published', $html);
        $this->assertStringContainsString('50%', $html);
        $this->assertStringContainsString('Vetting rejections', $html);
        $this->assertStringContainsString('applied 2 adjustment', $html);
        $text = implode("\n", PlanReportSection::text($s));
        $this->assertStringContainsString('Blog posts published: 3 of 6 planned (50%)', $text);

        $empty = PlanReportSection::html(['configured' => false, 'note' => 'No plan was recorded for 2026-10-17 yet']);
        $this->assertStringContainsString('No plan was recorded', $empty);
        $this->assertStringContainsString('could not', PlanReportSection::html(['configured' => true, 'note' => 'This section could not be built: x']));
    }

    public function testSchedulerRunsTheDailyPlannerAt1amIstAndRefreshesHourly(): void
    {
        $at = static function (string $utc): array {
            $out = [];
            foreach (ReachSchedule::plannedJobs(new \DateTimeImmutable($utc, new \DateTimeZone('UTC'))) as $job) {
                $out[$job['type']] = $job;
            }

            return $out['reach.bot_plan'];
        };
        // 19:45 UTC = 01:15 IST next day.
        $daily = $at('2026-10-17 19:45:00');
        $this->assertSame(['mode' => 'daily', 'date' => '2026-10-18'], $daily['payload']);
        $this->assertSame('bot-plan-daily-2026-10-18', $daily['opts']['idempotency_key']);
        $this->assertSame($daily['opts']['idempotency_key'], $at('2026-10-17 20:20:00')['opts']['idempotency_key'], 'Once per IST date.');

        $refresh = $at('2026-10-17 05:10:00');
        $this->assertSame('refresh', $refresh['payload']['mode']);
        $this->assertSame('bot-plan-refresh-2026-10-17-10', $refresh['opts']['idempotency_key']);
    }

    public function testBriefingGuidanceIsActionable(): void
    {
        $this->assertStringContainsString('avoid_reasons', (string) PlanBriefing::guidance(['code' => 'vetting_rejections']));
        $this->assertStringContainsString('stop', (string) PlanBriefing::guidance(['code' => 'automation_off']));
        $this->assertNull(PlanBriefing::guidance(null));
    }
}

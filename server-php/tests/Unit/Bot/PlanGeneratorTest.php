<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Libraries\Bot\Plan\PlanForecaster;
use App\Libraries\Bot\Plan\PlanGenerator;
use App\Libraries\Bot\Plan\PlanPeriods;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Plan generation is deterministic from configuration + schedule + history:
 * every number says where it came from, and too little history gives
 * "baseline being established", never an invented number.
 */
final class PlanGeneratorTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private static function inputs(array $overrides = []): array
    {
        $base = [
            'today'  => '2026-10-18',
            'config' => [
                'blog'           => ['enabled' => true, 'daily_limit' => 6, 'auto_publish' => true, 'ai_cover' => true],
                'knowledge_base' => ['enabled' => true, 'daily_limit' => 10, 'auto_publish' => true, 'cadence_total' => 8, 'cadence_products' => 5],
                'community'      => ['enabled' => true, 'daily_limit' => 30],
                'release_note'   => ['enabled' => true, 'daily_limit' => 40],
                'workflow_guide' => ['enabled' => false, 'daily_limit' => 40],
                'video'          => ['enabled' => true, 'vlogs' => 1, 'shorts' => 2, 'youtube_publisher' => 'social_api'],
                'seo'            => ['enabled' => true, 'max_per_day' => 35, 'active_keywords' => 20],
            ],
            'scheduled' => [
                'social'   => ['2026-10-19' => ['twitter' => 2, 'linkedin' => 1]],
                'email'    => ['2026-10-20' => ['recipients' => 500, 'dispatches' => 1]],
                'calendar' => [], 'content_schedules' => [], 'campaigns' => [], 'topic_queue' => ['available' => 4, 'roadmap_selected' => 1], 'items' => [],
            ],
            'history'           => ['series' => [], 'first_dates' => [], 'notes' => []],
            'overrides'         => [],
            'self_tune_enabled' => true,
        ];

        return array_replace_recursive($base, $overrides);
    }

    /** @return array<string, array<string, mixed>> */
    private static function items(array $inputs, string $type, string $start, string $end): array
    {
        return array_column(PlanGenerator::generate($inputs, $type, $start, $end)['items'], null, 'metric_key');
    }

    public function testDayTargetsComeFromLimitsCappedByRoutineCapacityAndCadence(): void
    {
        $i = self::items(self::inputs(), 'day', '2026-10-18', '2026-10-18');

        $this->assertSame(6.0, $i['content.blog.published']['target_value']);
        $this->assertSame('config', $i['content.blog.published']['source']);
        $this->assertStringContainsString('automation.blog.daily_limit = 6', $i['content.blog.published']['rationale']);
        $this->assertSame(8.0, $i['content.knowledge_base.published']['target_value'], 'Cadence tiers (8) are below the limit (10).');
        $this->assertSame(21.0, $i['content.community.published']['target_value'], 'Limit 30 capped by routine capacity 7 × 3.');
        $this->assertSame(6.0, $i['covers.generated']['target_value'], 'One cover per planned blog.');
        $this->assertSame(1.0, $i['video.vlog.published']['target_value']);
        $this->assertSame(2.0, $i['video.short.published']['target_value']);
        $this->assertSame(20.0, $i['seo.checks_run']['target_value'], 'min(35 per day, 20 enabled keywords).');
        $this->assertSame('off', $i['content.workflow_guide.published']['status']);
        $this->assertSame(3.0, $i['social.youtube.posted']['target_value'], 'Each video goes to YouTube through social-api.ai.');
        $this->assertSame(3.0, $i['social.instagram.posted']['target_value']);
        $this->assertSame(0.0, $i['social.twitter.posted']['target_value'], 'Nothing scheduled today for X.');
        $this->assertSame('high', $i['content.blog.published']['confidence']);
    }

    public function testScheduledSocialAndEmailLandOnTheirDates(): void
    {
        $tomorrow = self::items(self::inputs(), 'day', '2026-10-19', '2026-10-19');
        $this->assertSame(2.0, $tomorrow['social.twitter.posted']['target_value']);
        $this->assertSame('schedule', $tomorrow['social.twitter.posted']['source']);
        $this->assertSame(4.0, $tomorrow['social.linkedin.posted']['target_value'], '1 scheduled + 3 video shares.');
        $this->assertSame('mixed', $tomorrow['social.linkedin.posted']['source']);

        $week = self::items(self::inputs(), 'week', '2026-10-19', '2026-10-25');
        $this->assertSame(500.0, $week['email.accepted']['target_value']);
        $this->assertSame(42.0, $week['content.blog.published']['target_value'], '6 a day × 7.');
        $this->assertStringContainsString('Sum of the 7 daily targets', $week['content.blog.published']['method']);
    }

    public function testSwitchesTurnTargetsOffWithAReason(): void
    {
        $i = self::items(self::inputs(['config' => ['blog' => ['enabled' => false], 'video' => ['enabled' => false]]]), 'day', '2026-10-18', '2026-10-18');
        $this->assertSame('off', $i['content.blog.published']['status']);
        $this->assertSame(0.0, $i['content.blog.published']['target_value']);
        $this->assertStringContainsString('switched off', $i['content.blog.published']['rationale']);
        $this->assertSame('off', $i['covers.generated']['status'], 'No blogs → no covers.');
        $this->assertSame(0.0, $i['social.youtube.posted']['target_value'], 'No videos → no YouTube posts.');

        $noPublish = self::items(self::inputs(['config' => ['knowledge_base' => ['auto_publish' => false]]]), 'day', '2026-10-18', '2026-10-18');
        $this->assertSame('off', $noPublish['content.knowledge_base.published']['status']);
        $this->assertStringContainsString('Auto-publish is off', $noPublish['content.knowledge_base.published']['rationale']);
    }

    public function testOutcomesWithoutHistorySayBaselineInsteadOfANumber(): void
    {
        $i = self::items(self::inputs(), 'week', '2026-10-19', '2026-10-25');
        foreach (['search.clicks', 'leads.captured', 'engage.revenue_inr', 'content.release_note.published'] as $k) {
            $this->assertSame('baseline', $i[$k]['status'], $k);
            $this->assertNull($i[$k]['target_value'], $k);
            $this->assertStringContainsString('Baseline being established', $i[$k]['rationale'], $k);
        }
    }

    public function testOutcomeForecastFromTrailingHistoryStatesMethodAndConfidence(): void
    {
        $series = [];
        foreach (PlanPeriods::dates('2026-09-20', '2026-10-17') as $n => $d) {
            $series[$d] = 100.0 + ($n % 2 === 0 ? 5 : -5); // flat ~100/day, 28 days
        }
        $in = self::inputs(['history' => ['series' => ['search.clicks' => $series], 'first_dates' => ['search.clicks' => '2026-09-01']]]);
        $i  = self::items($in, 'week', '2026-10-19', '2026-10-25');

        $c = $i['search.clicks'];
        $this->assertSame('target', $c['status']);
        $this->assertSame('trend', $c['source']);
        $this->assertEqualsWithDelta(700.0, $c['target_value'], 15.0);
        $this->assertSame('high', $c['confidence']);
        $this->assertStringContainsString('28-day trailing mean', $c['method']);
        $this->assertLessThan($c['target_value'], $c['low_value']);
        $this->assertGreaterThan($c['target_value'], $c['high_value']);
    }

    public function testForecasterIgnoresDaysBeforeTheSourceExistedAndClampsTheTrend(): void
    {
        $series = [];
        foreach (PlanPeriods::dates('2026-09-01', '2026-10-17') as $n => $d) {
            $series[$d] = $d < '2026-10-10' ? 0.0 : 10.0;
        }
        $f = PlanForecaster::forecast($series, '2026-10-10', '2026-10-17', '2026-10-18', '2026-10-18');
        $this->assertSame('baseline', $f['status'], 'Only 8 days since the first record.');
        $this->assertSame(8, $f['days_used']);

        $rising = [];
        foreach (PlanPeriods::dates('2026-09-20', '2026-10-17') as $n => $d) {
            $rising[$d] = (float) ($n * 10);
        }
        $g = PlanForecaster::forecast($rising, '2026-09-01', '2026-10-17', '2026-11-01', '2026-11-30');
        $level = array_sum($rising) / 28;
        $this->assertLessThanOrEqual(round($level * 1.5 * 30), $g['target'], 'Trend projection clamped to +50 % of the level.');
        $this->assertNotSame('high', $g['confidence'], 'A steep series is not high confidence.');
    }

    public function testSelfTuneOverrideLowersTargetOnlyWhileEnabled(): void
    {
        $ov = ['content.blog.published' => ['target_value' => 3.0, 'base_value' => 6.0, 'cause' => 'routine_inactive', 'reason' => 'Lagged 7 days.', 'since' => '2026-10-17']];
        $on = self::items(self::inputs(['overrides' => $ov]), 'week', '2026-10-19', '2026-10-25');
        $this->assertSame(21.0, $on['content.blog.published']['target_value']);
        $this->assertSame(42.0, $on['content.blog.published']['base_target']);
        $this->assertSame('self_tune', $on['content.blog.published']['source']);
        $this->assertStringContainsString('Lowered by the self-tuner', $on['content.blog.published']['rationale']);

        $off = self::items(self::inputs(['overrides' => $ov, 'self_tune_enabled' => false]), 'week', '2026-10-19', '2026-10-25');
        $this->assertSame(42.0, $off['content.blog.published']['target_value'], 'Switch off → overrides ignored.');
        $this->assertSame('config', $off['content.blog.published']['source']);
    }

    public function testFingerprintIsStableAndChangesWithTheNumbers(): void
    {
        $a = PlanGenerator::generate(self::inputs(), 'day', '2026-10-18', '2026-10-18')['items'];
        $b = PlanGenerator::generate(self::inputs(), 'day', '2026-10-18', '2026-10-18')['items'];
        $c = PlanGenerator::generate(self::inputs(['config' => ['blog' => ['daily_limit' => 4]]]), 'day', '2026-10-18', '2026-10-18')['items'];
        $this->assertSame(PlanGenerator::fingerprint($a), PlanGenerator::fingerprint($b));
        $this->assertNotSame(PlanGenerator::fingerprint($a), PlanGenerator::fingerprint($c));
    }

    public function testPeriodsAreIstCalendarPeriods(): void
    {
        // 2026-10-18 20:00 UTC is already Monday 19 Oct 01:30 in IST.
        $now = new \DateTimeImmutable('2026-10-18 20:00:00', new \DateTimeZone('UTC'));
        $this->assertSame('2026-10-19', PlanPeriods::plan('today', $now)['start']);
        $this->assertSame(['week', '2026-10-19', '2026-10-25'], array_values(array_intersect_key(PlanPeriods::plan('this_week', $now), array_flip(['type', 'start', 'end']))));
        $this->assertSame('2026-11-30', PlanPeriods::plan('next_month', $now)['end']);
        $this->assertSame(['2026-10-12', '2026-10-18'], [PlanPeriods::range('last_week', $now)['from'], PlanPeriods::range('last_week', $now)['to']]);
        $this->assertSame('2026-10-18', PlanPeriods::range('this_week', $now)['to'], 'On a Monday "this week so far" falls back to yesterday.');
        $this->expectException(\InvalidArgumentException::class);
        PlanPeriods::plan('someday');
    }
}

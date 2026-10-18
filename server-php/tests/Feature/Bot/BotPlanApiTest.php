<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Libraries\Bot\Plan\BotPlanRunner;
use App\Libraries\Bot\Plan\LagSignals;
use App\Libraries\Bot\Plan\PlanPeriods;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\Seo\DataForSeoSerpClient;
use App\Libraries\Intelligence\Seo\SeoTrackerService;
use App\Libraries\Reports\DailyReportBuilder;
use App\Libraries\Reports\DailyReportRenderer;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * GET v1/bot/plan, GET v1/bot/performance (bot.view), GET
 * v1/automation/plan/briefing (automation token), the daily planner run and
 * the daily report's "Plan vs achieved (yesterday)" section.
 */
final class BotPlanApiTest extends ApiTestCase
{
    use BotPlanFixtures;

    private const TOKEN = 'test-automation-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        ReachConfig::flush();
        $_ENV['REACH_AUTOMATION_TOKEN'] = self::TOKEN;
        putenv('REACH_AUTOMATION_TOKEN=' . self::TOKEN);
    }

    protected function tearDown(): void
    {
        unset($_ENV['REACH_AUTOMATION_TOKEN']);
        putenv('REACH_AUTOMATION_TOKEN');
        ReachConfig::flush();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function json($res): array
    {
        return json_decode($res->getJSON(), true);
    }

    public function testPlanEndpointIsPermissionGatedAndValidatesThePeriod(): void
    {
        $this->call('GET', 'v1/bot/plan')->assertStatus(401);
        $this->withHeaders($this->authAs('viewer'))->call('GET', 'v1/bot/plan')->assertStatus(403);

        $h = $this->authAs('super_admin');
        $this->withHeaders($h)->call('GET', 'v1/bot/plan', ['period' => 'someday'])->assertStatus(422);

        $res = $this->withHeaders($h)->call('GET', 'v1/bot/plan', ['period' => 'next_week']);
        $res->assertStatus(200);
        $d = $this->json($res)['data'];
        $this->assertSame('next_week', $d['period']['key']);
        $this->assertSame(7, $d['period']['days']);
        $this->assertSame(PlanPeriods::PLAN_KEYS, $d['periods']);
        $items = array_column($d['items'], null, 'metric_key');
        $this->assertSame('Blog posts published', $items['content.blog.published']['label']);
        $this->assertSame(42.0, (float) $items['content.blog.published']['target_value']);
        $this->assertSame('baseline', $items['search.clicks']['status']);
        $this->assertNull($items['search.clicks']['target_value']);
        $this->assertArrayHasKey('scheduled', $d);
        $this->assertSame('Asia/Kolkata', $d['timezone']);
    }

    public function testPerformanceEndpointShowsVarianceCausesAndTheSelfTuneLog(): void
    {
        $y = PlanPeriods::addDays(PlanPeriods::today(), -1);
        $this->seedDayPlans(PlanPeriods::addDays($y, -6), $y);
        $this->seedActuals('content.blog.published', array_fill_keys(PlanPeriods::dates(PlanPeriods::addDays($y, -6), $y), 2.0));

        $h = $this->authAs('super_admin');
        $this->withHeaders($h)->call('GET', 'v1/bot/performance', ['range' => 'forever'])->assertStatus(422);
        $res = $this->withHeaders($h)->call('GET', 'v1/bot/performance', ['range' => 'last_7_days']);
        $res->assertStatus(200);
        $d = $this->json($res)['data'];

        $blog = array_column($d['metrics'], null, 'metric_key')['content.blog.published'];
        $this->assertSame(42.0, (float) $blog['target']);
        $this->assertSame(14.0, (float) $blog['achieved']);
        $this->assertSame(33, $blog['achievement_pct']);
        $this->assertSame('lagging', $blog['status']);
        $this->assertNotEmpty($blog['causes']);
        $this->assertSame('integration_missing', $blog['causes'][0]['code'], 'No Gemini key in the test environment: vetting cannot run.');
        $this->assertStringContainsString('Gemini', $blog['causes'][0]['label']);
        $this->assertContains('routine_inactive', array_column($blog['causes'], 'code'), 'No routine submissions in the fixture.');
        $this->assertCount(7, $blog['series']);
        $this->assertNotEmpty($d['top_lags']);
        $this->assertArrayHasKey('log', $d['self_tune']);
        $this->assertSame(0.5, (float) $d['self_tune']['bounds']['min_fraction_of_base']);
        $this->assertSame(7, $d['coverage']['days_with_plan']);
        $this->assertNotEmpty($d['recommendations']);
    }

    public function testBriefingNeedsTheAutomationTokenAndCarriesLessons(): void
    {
        $this->call('GET', 'v1/automation/plan/briefing')->assertStatus(401);
        $h = ['Authorization' => 'Bearer ' . self::TOKEN, 'Accept' => 'application/json'];
        $this->withHeaders($h)->call('GET', 'v1/automation/plan/briefing', ['type' => 'tiktok'])->assertStatus(422);

        $db = Database::connect();
        $db->table('reach_ai_vetting_runs')->insert(['content_type' => 'blog', 'model' => 'gemini-test', 'decision' => 'reject', 'input_sha256' => str_repeat('b', 64),
            'reasons_json' => json_encode(['States a GST rate that is not in force']), 'created_at' => gmdate('Y-m-d H:i:sP', time() - 86400)]);

        $res = $this->withHeaders($h)->call('GET', 'v1/automation/plan/briefing', ['type' => 'blog']);
        $res->assertStatus(200);
        $d = $this->json($res)['data'];
        $this->assertSame('blog', $d['type']);
        $this->assertSame(['content.blog.published', 'covers.generated'], array_column($d['today'], 'metric_key'));
        $this->assertStringContainsString('Read lessons first', $d['read_me']);
        $this->assertSame('States a GST rate that is not in force', $d['avoid_reasons']['blog'][0]['reason']);
        $this->assertArrayHasKey('prefer', $d['topic_mix']);
        $this->assertTrue($d['self_tune_enabled']);
    }

    public function testDailyRunStoresAReviewThatTheDailyReportShows(): void
    {
        $db = Database::connect();
        $y  = PlanPeriods::addDays(PlanPeriods::today(), -1);
        $this->seedDayPlans(PlanPeriods::addDays($y, -6), $y);
        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Y', 'slug' => 'y',
            'published_at' => (new \DateTimeImmutable($y . ' 12:00:00', new \DateTimeZone('Asia/Kolkata')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP')]);

        $out = (new BotPlanRunner($db, new LagSignals($db, self::integrations())))->run('daily');
        $this->assertSame('daily', $out['mode']);
        $this->assertGreaterThan(0, $out['actuals']['rows']);
        $this->assertSame($y, $out['review']['date']);
        $this->assertSame(1, $db->table('reach_bot_performance_reviews')->where('review_date', $y)->countAllResults());
        $this->assertCount(6, $out['plans']);

        $builder = new DailyReportBuilder($db, null, new SeoTrackerService($db, new DataForSeoSerpClient(null, null)), static fn (): bool => false, static fn (): string => 'email: no provider configured');
        $summary = $builder->build(PlanPeriods::today());
        $plan    = $summary['sections']['plan'];
        $this->assertTrue($plan['configured']);
        $this->assertSame($y, $plan['date']);
        $blog = array_column($plan['metrics'], null, 'metric_key')['content.blog.published'];
        $this->assertSame(6.0, (float) $blog['target']);
        $this->assertSame(1.0, (float) $blog['achieved']);

        $html = DailyReportRenderer::html($summary);
        $this->assertStringContainsString('Plan vs achieved (yesterday)', $html);
        $this->assertStringContainsString('Blog posts published', $html);
        $this->assertStringContainsString('== Plan vs achieved (yesterday) ==', DailyReportRenderer::text($summary));

        // Second run the same day: review upserted, plans not re-versioned.
        $again = (new BotPlanRunner($db, new LagSignals($db, self::integrations())))->run('daily');
        $this->assertSame([], array_filter($again['plans'], static fn ($p) => $p['created']));
        $this->assertSame(1, $db->table('reach_bot_performance_reviews')->where('review_date', $y)->countAllResults());
    }

    public function testReportSaysSoWhenNoPlanWasRecorded(): void
    {
        $db      = Database::connect();
        $builder = new DailyReportBuilder($db, null, new SeoTrackerService($db, new DataForSeoSerpClient(null, null)), static fn (): bool => false, static fn (): string => 'email: no provider configured');
        $plan    = $builder->build('2026-10-17', new \DateTimeImmutable('2026-10-17 08:00:00', new \DateTimeZone('Asia/Kolkata')))['sections']['plan'];
        $this->assertFalse($plan['configured']);
        $this->assertStringContainsString('No plan was recorded for 2026-10-16', $plan['note']);
    }
}

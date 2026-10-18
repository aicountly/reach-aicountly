<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Libraries\Bot\Plan\ActualsCollector;
use App\Libraries\Bot\Plan\ActualsSnapshotService;
use App\Libraries\Bot\Plan\PlanService;
use App\Libraries\Bot\Plan\PlanStore;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Actuals are counted per IST day from the pipeline tables; plans are
 * generated from the real settings, versioned only when they change, and the
 * day plan of record is the one generated before that day ended.
 */
final class PlanAndActualsTest extends DatabaseTestCase
{
    use BotPlanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        ReachConfig::flush();
        parent::tearDown();
    }

    private function istToUtc(string $ist): string
    {
        return (new \DateTimeImmutable($ist, new \DateTimeZone('Asia/Kolkata')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }

    public function testSnapshotCountsWhatHappenedPerIstDay(): void
    {
        $db = Database::connect();
        // 23:30 IST on the 16th and 00:30 IST on the 17th are different IST days (same UTC day).
        foreach (['2026-10-16 23:30:00', '2026-10-17 00:30:00', '2026-10-17 12:00:00'] as $i => $at) {
            $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => "Blog {$i}", 'slug' => "blog-{$i}", 'published_at' => $this->istToUtc($at)]);
        }
        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Draft', 'slug' => 'draft']);
        $db->table('reach_content_items')->insert(['content_type' => 'knowledge_base', 'title' => 'KB', 'slug' => 'kb', 'published_at' => $this->istToUtc('2026-10-17 10:00:00')]);
        $db->table('reach_social_posts')->insert(['channel' => 'linkedin', 'status' => 'posted', 'published_at' => $this->istToUtc('2026-10-17 09:00:00')]);
        $db->table('reach_social_posts')->insert(['channel' => 'linkedin', 'status' => 'failed', 'published_at' => $this->istToUtc('2026-10-17 09:00:00')]);
        $db->table('reach_video_pipeline_items')->insert(['external_key' => 'v1', 'format' => 'short', 'title' => 'S', 'submitted_json' => '{}', 'script_json' => '{}',
            'status' => 'published', 'published_at' => $this->istToUtc('2026-10-17 15:00:00')]);
        $db->table('reach_leads')->insert(['name' => 'A', 'created_at' => $this->istToUtc('2026-10-17 11:00:00'), 'engage_push_status' => 'pushed', 'last_push_at' => $this->istToUtc('2026-10-17 11:05:00')]);
        $db->table('reach_lead_outcomes')->insert(['event_id' => 'e1', 'stage' => 'payment_confirmed', 'value_inr' => '4999.00', 'occurred_at' => $this->istToUtc('2026-10-17 18:00:00')]);
        $db->table('reach_lead_outcomes')->insert(['event_id' => 'e2', 'stage' => 'converted', 'engage_lead_code' => 'L1', 'occurred_at' => $this->istToUtc('2026-10-17 17:00:00')]);
        $db->table('reach_media_gallery_assets')->insert(['asset_uuid' => 'a1', 'kind' => 'ai_generated', 'file_path' => 'x.png', 'checksum_sha256' => str_repeat('a', 64), 'created_at' => $this->istToUtc('2026-10-17 12:01:00')]);

        $now = new \DateTimeImmutable('2026-10-18 09:00:00', new \DateTimeZone('Asia/Kolkata'));
        $res = (new ActualsSnapshotService($db))->snapshot('2026-10-16', '2026-10-18', $now);
        $this->assertSame('2026-10-17', $res['to'], 'Today is never snapshotted.');

        $a = (new ActualsSnapshotService($db))->read('2026-10-16', '2026-10-17');
        $this->assertSame(1.0, $a['content.blog.published']['2026-10-16']);
        $this->assertSame(2.0, $a['content.blog.published']['2026-10-17']);
        $this->assertSame(1.0, $a['content.knowledge_base.published']['2026-10-17']);
        $this->assertSame(1.0, $a['social.linkedin.posted']['2026-10-17'], 'Failed posts do not count.');
        $this->assertSame(0.0, $a['social.twitter.posted']['2026-10-17'], 'A quiet day is a real 0.');
        $this->assertSame(1.0, $a['video.short.published']['2026-10-17']);
        $this->assertSame(1.0, $a['leads.captured']['2026-10-17']);
        $this->assertSame(1.0, $a['leads.pushed']['2026-10-17']);
        $this->assertSame(1.0, $a['engage.conversions']['2026-10-17']);
        $this->assertSame(4999.0, $a['engage.revenue_inr']['2026-10-17']);
        $this->assertSame(1.0, $a['covers.generated']['2026-10-17']);
        $this->assertNull($a['search.clicks']['2026-10-17'], 'Search Console has not reported → null, not 0.');

        // Re-snapshotting is an upsert, not a duplicate.
        (new ActualsSnapshotService($db))->snapshot('2026-10-16', '2026-10-17', $now);
        $this->assertSame(1, $db->table('reach_bot_plan_actuals')->where('metric_date', '2026-10-17')->where('metric_key', 'content.blog.published')->countAllResults());
    }

    public function testRegenerationIsIdempotentVersionedAndReadsRealSettings(): void
    {
        $db  = Database::connect();
        $now = new \DateTimeImmutable('2026-10-18 01:00:00', new \DateTimeZone('Asia/Kolkata'));
        ReachConfig::set('automation.blog.daily_limit', 4);
        ReachConfig::flush();

        $svc   = new PlanService($db);
        $first = $svc->regenerate($now);
        $this->assertTrue($first['today']['created']);
        $this->assertSame(1, $first['today']['version']);

        $again = $svc->regenerate($now->modify('+1 hour'));
        $this->assertFalse($again['today']['created'], 'Nothing changed → no new version.');
        $this->assertSame(6, $db->table('reach_bot_plans')->countAllResults(), 'One row per period.');

        $view = $svc->view('today', $now);
        $blog = array_column($view['items'], null, 'metric_key')['content.blog.published'];
        $this->assertSame(4.0, $blog['target_value']);
        $this->assertStringContainsString('automation.blog.daily_limit = 4', $blog['rationale']);
        $this->assertTrue($view['plan']['stored']);
        $kb = array_column($view['items'], null, 'metric_key')['content.knowledge_base.published'];
        $this->assertStringContainsString('cadence', $kb['rationale'], 'KB cadence tiers from reach_kb_product_cadence.');

        // The owner raises the limit at 15:00 → a new version for the open periods; history kept.
        ReachConfig::set('automation.blog.daily_limit', 8);
        ReachConfig::flush();
        $later = $svc->regenerate(new \DateTimeImmutable('2026-10-18 15:00:00', new \DateTimeZone('Asia/Kolkata')));
        $this->assertSame(2, $later['today']['version']);
        $this->assertCount(2, (new PlanStore($db))->versions('day', '2026-10-18'));
        $week = array_column($svc->view('this_week', $now)['items'], null, 'metric_key')['content.blog.published'];
        $this->assertSame(56.0, $week['target_value']);

        // Tomorrow's regeneration (after midnight) does not rewrite the 18th's plan of record.
        ReachConfig::set('automation.blog.daily_limit', 2);
        ReachConfig::flush();
        $store = new PlanStore($db);
        $plan  = \App\Libraries\Bot\Plan\PlanGenerator::generate(self::planInputs('2026-10-19', ['blog' => ['daily_limit' => 2]]), 'day', '2026-10-18', '2026-10-18');
        $store->save('day', '2026-10-18', '2026-10-18', $plan['items'], $plan['summary'], new \DateTimeImmutable('2026-10-19 02:00:00', new \DateTimeZone('Asia/Kolkata')));
        $this->assertSame(8.0, $store->dayPlans('2026-10-18', '2026-10-18')['2026-10-18']['content.blog.published']['target_value']);
    }

    public function testViewShowsAchievedSoFarAndAPreviewBeforeTheFirstRun(): void
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Today', 'slug' => 'today',
            'published_at' => $this->istToUtc('2026-10-18 10:00:00')]);
        $now  = new \DateTimeImmutable('2026-10-18 12:00:00', new \DateTimeZone('Asia/Kolkata'));
        $view = (new PlanService($db))->view('today', $now);

        $this->assertFalse($view['plan']['stored']);
        $this->assertStringContainsString('Preview', $view['plan']['note']);
        $blog = array_column($view['items'], null, 'metric_key')['content.blog.published'];
        $this->assertSame(1.0, $blog['achieved_to_date']);
        $this->assertTrue($view['period']['partial_today']);

        $next = (new PlanService($db))->view('next_month', $now);
        $this->assertSame(0, $next['period']['elapsed_days']);
        $this->assertNull(array_column($next['items'], null, 'metric_key')['content.blog.published']['achieved_to_date']);
        $this->assertSame(180.0, array_column($next['items'], null, 'metric_key')['content.blog.published']['target_value'], '6 a day × 30 November days.');
    }

    public function testCollectorReportsSearchFromSearchConsoleWhenIngested(): void
    {
        $db = Database::connect();
        $db->table('reach_analytics_connections')->insert(['tenant_id' => 1, 'provider' => 'gsc', 'display_name' => 'GSC', 'site_property' => 'sc-domain:aicountly.com', 'enabled' => true]);
        $cid = (int) $db->insertID();
        $db->table('reach_search_site_daily')->insert(['connection_id' => $cid, 'site_property' => 'sc-domain:aicountly.com', 'metric_date' => '2026-10-14', 'device' => 'all', 'clicks' => 42, 'impressions' => 900, 'ctr' => 0.05, 'avg_position' => 8.1]);

        $c = (new ActualsCollector($db))->collect('2026-10-14', '2026-10-15');
        $this->assertSame(42.0, $c['series']['search.clicks']['2026-10-14']);
        $this->assertSame(900.0, $c['series']['search.impressions']['2026-10-14']);
        $this->assertNull($c['series']['search.clicks']['2026-10-15']);
        $this->assertSame('2026-10-14', $c['first_dates']['search.clicks']);
        $this->assertStringContainsString('reported up to 2026-10-14', $c['notes']['search.clicks']);
    }
}

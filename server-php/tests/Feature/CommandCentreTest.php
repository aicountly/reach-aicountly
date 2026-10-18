<?php

namespace Tests\Feature;

use App\Libraries\Dashboard\CommandCentreService;
use App\Libraries\Database\SchemaGuard;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * GET v1/dashboard/command-centre — every section from real rows, honest
 * "empty" when there are none, and each section failing on its own.
 */
final class CommandCentreTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        foreach (CommandCentreService::SECTIONS as $section) {
            \Config\Services::cache()->delete('reach_cc_' . $section);
        }
    }

    /** @return array<string, mixed> */
    private function fetch(string $query = 'fresh=1'): array
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/dashboard/command-centre?' . $query);
        $this->assertSame(200, $res->response()->getStatusCode());

        return json_decode((string) $res->getJSON(), true)['data'];
    }

    private function ts(string $rel): string
    {
        return date('Y-m-d H:i:s', strtotime($rel));
    }

    public function testEmptyPortalSaysSoInsteadOfInventingNumbers(): void
    {
        $d = $this->fetch();

        foreach (CommandCentreService::SECTIONS as $s) {
            $this->assertArrayHasKey($s, $d, $s);
            $this->assertNotSame('error', $d[$s]['status'], $s . ': ' . ($d[$s]['message'] ?? ''));
        }
        $this->assertSame('empty', $d['activity']['status']);
        $this->assertSame('empty', $d['impact']['status']);
        $this->assertFalse($d['impact']['search']['available']);
        $this->assertSame('empty', $d['rankings']['status']);
        $this->assertFalse($d['projections']['forecasts']['clicks']['available']);
        $this->assertNotEmpty($d['projections']['quotas']);
        $this->assertCount(6, $d['health']['routines']);
        // Nothing configured in tests: those become action cards with a settings link.
        $ids = array_column($d['recommendations']['cards'], 'id');
        $this->assertContains('integration-gemini', $ids);
    }

    public function testActivityFeedListsWhatTheBotDidWithCounters(): void
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'content_type' => 'blog', 'title' => 'GST e-invoicing explained', 'slug' => 'gst-einv-' . bin2hex(random_bytes(3)),
            'workflow_status' => 'live', 'published_at' => $this->ts('-2 hours'),
        ]);
        $db->table('reach_ai_vetting_runs')->insert([
            'content_type' => 'blog', 'content_ref' => 'blog:9', 'model' => 'gemini-2.5-flash', 'decision' => 'reject',
            'reasons_json' => json_encode(['unsupported claim']), 'input_sha256' => str_repeat('a', 64), 'created_at' => $this->ts('-3 days'),
        ]);
        $db->table('reach_jobs')->insert([
            'job_type' => 'reach.seo_tracker', 'status' => 'dead_letter', 'error_message' => 'HTTP 402 balance',
            'completed_at' => $this->ts('-1 hour'), 'updated_at' => $this->ts('-1 hour'),
        ]);
        // Older than 30 days: not in the feed.
        $db->table('reach_content_items')->insert([
            'content_type' => 'knowledge_base', 'title' => 'Old', 'slug' => 'old-' . bin2hex(random_bytes(3)),
            'workflow_status' => 'published', 'published_at' => $this->ts('-40 days'),
        ]);

        $a = $this->fetch('fresh=1&sections=activity')['activity'];

        $this->assertSame('ok', $a['status']);
        $this->assertSame(['label' => 'Blog published', 'h24' => 1, 'd7' => 1, 'd30' => 1], $a['counters']['blog_published']);
        $this->assertSame(0, $a['counters']['vetting_rejected']['h24']);
        $this->assertSame(1, $a['counters']['vetting_rejected']['d7']);
        $this->assertSame(0, $a['counters']['kb_published']['d30']);

        $this->assertSame('job_failed', $a['events'][0]['kind'], 'newest first');
        $this->assertSame('negative', $a['events'][0]['tone']);
        $this->assertSame('GST e-invoicing explained', $a['events'][1]['title']);
        $this->assertStringStartsWith('/content/', $a['events'][1]['link']);
    }

    public function testImpactUsesSiteWideSearchConsoleAndLeadOutcomes(): void
    {
        $db = Database::connect();
        $db->table('reach_analytics_connections')->insert([
            'tenant_id' => 1, 'provider' => 'gsc', 'display_name' => 'GSC', 'site_property' => 'sc-domain:aicountly.com', 'enabled' => true, 'health_status' => 'healthy',
        ]);
        $conn = (int) $db->insertID();
        for ($i = 0; $i < 40; $i++) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $db->table('reach_search_site_daily')->insert([
                'connection_id' => $conn, 'site_property' => 'sc-domain:aicountly.com', 'metric_date' => $day, 'device' => '',
                'clicks' => 2, 'impressions' => 40, 'ctr' => 0.05, 'avg_position' => 12.0,
            ]);
            $db->table('reach_search_site_facts')->insert([
                'connection_id' => $conn, 'site_property' => 'sc-domain:aicountly.com', 'metric_date' => $day,
                'page_url' => 'https://aicountly.com/billing', 'query' => 'gst billing software', 'device' => '', 'country' => '',
                'clicks' => 2, 'impressions' => 40, 'ctr' => 0.05, 'avg_position' => 7.0,
            ]);
        }
        $db->table('reach_leads')->insert(['name' => 'A', 'email' => 'a@example.com', 'source_kind' => 'website', 'engage_push_status' => 'pushed', 'created_at' => $this->ts('-2 days'), 'updated_at' => $this->ts('-2 days')]);
        $leadId = (int) $db->insertID();
        $db->table('reach_lead_outcomes')->insert([
            'event_id' => 'evt-1', 'lead_id' => $leadId, 'stage' => 'won', 'sales_status' => 'won', 'value_inr' => 11800,
            'occurred_at' => $this->ts('-1 day'), 'received_at' => $this->ts('-1 day'), 'match_method' => 'reach_lead_id',
        ]);

        $d = $this->fetch('fresh=1&sections=impact,projections,recommendations');
        $s = $d['impact']['search'];

        $this->assertTrue($s['available']);
        $this->assertSame(56, $s['current']['clicks'], '28 days × 2 clicks, site-wide');
        $this->assertSame(0, $s['reach_share']['clicks'], 'no Reach-mapped rows');
        $this->assertTrue($s['low_volume']);
        $this->assertSame('gst billing software', $s['top_queries'][0]['dimension_value']);

        $this->assertSame(1, $d['impact']['leads']['current']);
        $this->assertSame(1, $d['impact']['outcomes']['conversions']);
        $this->assertEqualsWithDelta(11800.0, $d['impact']['outcomes']['revenue_inr'], 0.01);

        $clicks = $d['projections']['forecasts']['clicks'];
        $this->assertTrue($clicks['available'], 'forty days is enough');
        $this->assertSame('flat', $clicks['trend']);
        $this->assertEqualsWithDelta(56.0, $clicks['projected']['total'], 0.5);
        $this->assertStringContainsString('Least-squares', $clicks['method']);
        $this->assertFalse($d['projections']['forecasts']['leads']['available'], 'one lead day is not a trend');

        $ids = array_column($d['recommendations']['cards'], 'id');
        $this->assertContains('push-' . md5('gst billing software'), $ids, 'position 7 query with impressions');
    }

    public function testRankingsComparePositionsAndCompetitors(): void
    {
        $db = Database::connect();
        $db->table('reach_seo_tracked_keywords')->insert(['keyword' => 'cc test gst software', 'priority' => 'high', 'enabled' => true]);
        $k1 = (int) $db->insertID();
        $db->table('reach_seo_tracked_keywords')->insert(['keyword' => 'cc test tally alternative', 'priority' => 'medium', 'enabled' => true]);
        $k2 = (int) $db->insertID();
        $db->table('reach_competitors')->insert(['tenant_id' => 1, 'name' => 'Rival', 'website_domain' => 'rival.example', 'category' => 'direct', 'effective_from' => '2026-01-01']);
        $rival = (int) $db->insertID();

        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        foreach ([[$k1, $weekAgo, 15], [$k1, $today, 8], [$k2, $weekAgo, 30], [$k2, $today, null]] as [$k, $date, $rank]) {
            $db->table('reach_seo_serp_checks')->insert(['keyword_id' => $k, 'check_date' => $date, 'status' => 'ok', 'provider' => 'dataforseo', 'our_rank' => $rank, 'checked_at' => $date . ' 06:00:00']);
            $check = (int) $db->insertID();
            $db->table('reach_seo_serp_rankings')->insert(['check_id' => $check, 'keyword_id' => $k, 'check_date' => $date, 'competitor_id' => $rival, 'is_ours' => false, 'domain' => 'rival.example', 'rank_absolute' => 3]);
        }

        $t = $this->fetch('fresh=1&sections=rankings')['rankings']['tracker'];

        $this->assertTrue($t['available']);
        $this->assertSame($today, $t['latest_date']);
        $this->assertSame($weekAgo, $t['compared_with']);
        $this->assertSame(2, $t['keywords_checked']);
        $this->assertSame(1, $t['top10']);
        $this->assertSame(0.5, $t['top10_share']);
        $this->assertSame('cc test gst software', $t['movers_up'][0]['keyword']);
        $this->assertSame(7, $t['movers_up'][0]['change']);
        $this->assertSame('cc test tally alternative', $t['movers_down'][0]['keyword'], 'dropped out of the top 100');
        $this->assertSame('Rival', $t['competitors'][0]['name']);
        $this->assertSame(2, $t['competitors'][0]['top10']);
    }

    public function testHealthUsesTheLastRecordedLiveCheck(): void
    {
        \App\Libraries\Settings\LiveIntegrationCheck::remember([
            'ran_at' => '2026-10-18T08:00:00+00:00',
            'results' => [['target' => 'dataforseo', 'ok' => true, 'message' => 'Connected. Balance: $2.10', 'detail' => ['balance_usd' => 2.1, 'secret' => 'x']]],
        ]);
        putenv('DATAFORSEO_LOGIN=me@example.com');
        putenv('DATAFORSEO_PASSWORD=pw');
        $_ENV['DATAFORSEO_LOGIN'] = 'me@example.com';
        $_ENV['DATAFORSEO_PASSWORD'] = 'pw';
        \App\Libraries\Settings\ReachConfig::flush();

        try {
            $d = $this->fetch('fresh=1&sections=health,recommendations');
        } finally {
            putenv('DATAFORSEO_LOGIN');
            putenv('DATAFORSEO_PASSWORD');
            unset($_ENV['DATAFORSEO_LOGIN'], $_ENV['DATAFORSEO_PASSWORD']);
            \App\Libraries\Settings\ReachConfig::flush();
        }

        $stored = (new \App\Models\SettingModel())->getSetting(CommandCentreService::LIVE_CHECK_SETTING);
        $this->assertSame(['balance_usd' => 2.1], $stored['results'][0]['detail'], 'only the balance is kept');

        $this->assertSame('2026-10-18T08:00:00+00:00', $d['health']['live_checks_at']);
        $integrations = array_column($d['health']['integrations'], null, 'id');
        $this->assertSame('ok', $integrations['dataforseo']['state']);
        $this->assertContains('dataforseo-balance', array_column($d['recommendations']['cards'], 'id'));
    }

    public function testSectionsAreServedFromCacheUntilFresh(): void
    {
        $first = $this->fetch('sections=activity');
        Database::connect()->table('reach_content_items')->insert([
            'content_type' => 'blog', 'title' => 'New', 'slug' => 'new-' . bin2hex(random_bytes(3)),
            'workflow_status' => 'live', 'published_at' => date('Y-m-d H:i:s'),
        ]);
        $cached = $this->fetch('sections=activity');
        $fresh  = $this->fetch('sections=activity&fresh=1');

        $this->assertSame($first['activity']['counters']['blog_published']['d30'] ?? 0, $cached['activity']['counters']['blog_published']['d30'] ?? 0);
        $this->assertSame(($first['activity']['counters']['blog_published']['d30'] ?? 0) + 1, $fresh['activity']['counters']['blog_published']['d30']);
        $this->assertFalse($fresh['activity']['cached']);
    }
}

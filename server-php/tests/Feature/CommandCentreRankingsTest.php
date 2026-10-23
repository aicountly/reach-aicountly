<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Dashboard\CommandCentreService;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use App\Libraries\Intelligence\Visibility\AssistantAnswer;
use App\Libraries\Intelligence\Visibility\AssistantClient;
use App\Libraries\Intelligence\Visibility\AssistantException;
use App\Libraries\Intelligence\Visibility\AssistantRegistry;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Command centre → Rankings: the detail behind every tile (which keywords,
 * which competitor holds what, which AI questions named whom) and the
 * drill-down endpoints `v1/dashboard/command-centre/rankings/*`.
 *
 * @covers \App\Libraries\Dashboard\RankingsDrilldown
 * @covers \App\Libraries\Dashboard\CommandCentreService
 */
final class CommandCentreRankingsTest extends ApiTestCase
{
    private string $today;
    private string $weekAgo;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        foreach (CommandCentreService::SECTIONS as $section) {
            \Config\Services::cache()->delete('reach_cc_' . $section);
        }
        $db = Database::connect();
        $db->query('TRUNCATE reach_ai_visibility_citations, reach_ai_visibility_observations, reach_ai_visibility_responses, reach_ai_visibility_runs, reach_ai_visibility_batches RESTART IDENTITY CASCADE');
        $db->query("DELETE FROM reach_settings WHERE key LIKE 'visibility.%'");
        // The migration seeds a default keyword list; these tests use their own.
        $db->query('DELETE FROM reach_seo_tracked_keywords');
        ReachConfig::flush();
        $this->today   = date('Y-m-d');
        $this->weekAgo = date('Y-m-d', strtotime('-7 days'));
    }

    protected function tearDown(): void
    {
        ReachConfig::flush();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function fetch(string $uri, string $role = 'super_admin', int $status = 200): array
    {
        $res = $this->withHeaders($this->authAs($role))->call('GET', $uri);
        $this->assertSame($status, $res->response()->getStatusCode(), (string) $res->getJSON());

        return json_decode((string) $res->getJSON(), true)['data'] ?? [];
    }

    /** @return array{gst:int, tally:int, payroll:int, einv:int, rival:int, other:int} */
    private function seedTracker(): array
    {
        $db = Database::connect();
        $kw = static function (string $keyword, string $product, bool $enabled = true) use ($db): int {
            $db->table('reach_seo_tracked_keywords')->insert(['keyword' => $keyword, 'product' => $product, 'priority' => 'high', 'enabled' => $enabled]);

            return (int) $db->insertID();
        };
        $ids = [
            'gst'     => $kw('rk gst billing software', 'billing'),
            'tally'   => $kw('rk tally alternative', 'books'),
            'payroll' => $kw('rk payroll software india', 'payroll'),
            'einv'    => $kw('rk e-invoice generator', 'billing'),
        ];
        $db->table('reach_competitors')->insert(['tenant_id' => 1, 'name' => 'RK Rival', 'website_domain' => 'rival.example', 'category' => 'direct', 'effective_from' => '2026-01-01']);
        $ids['rival'] = (int) $db->insertID();
        $db->table('reach_competitors')->insert(['tenant_id' => 1, 'name' => 'RK Other', 'website_domain' => 'other.example', 'category' => 'direct', 'effective_from' => '2026-01-01']);
        $ids['other'] = (int) $db->insertID();

        $check = function (int $k, string $date, string $status, ?int $rank, ?string $url = null, array $comps = []) use ($db): void {
            $db->table('reach_seo_serp_checks')->insert(['keyword_id' => $k, 'check_date' => $date, 'status' => $status, 'provider' => 'dataforseo',
                'our_rank' => $rank, 'our_url' => $url, 'error' => $status === 'failed' ? 'HTTP 402 balance' : null, 'checked_at' => $date . ' 00:35:00+00']);
            $id = (int) $db->insertID();
            foreach ($comps as [$cid, $domain, $r]) {
                $db->table('reach_seo_serp_rankings')->insert(['check_id' => $id, 'keyword_id' => $k, 'check_date' => $date, 'competitor_id' => $cid,
                    'is_ours' => false, 'domain' => $domain, 'rank_absolute' => $r, 'url' => "https://{$domain}/{$r}"]);
            }
        };
        // gst: 15 → 8 (up 7); Rival #3 and Other #12 now.
        $check($ids['gst'], $this->weekAgo, 'ok', 15, 'https://aicountly.com/billing');
        $check($ids['gst'], $this->today, 'ok', 8, 'https://aicountly.com/billing', [[$ids['rival'], 'rival.example', 3], [$ids['other'], 'other.example', 12]]);
        // tally: 30 → out of the top 100 (down); Rival #5.
        $check($ids['tally'], $this->weekAgo, 'ok', 30, 'https://aicountly.com/tally');
        $check($ids['tally'], $this->today, 'ok', null, null, [[$ids['rival'], 'rival.example', 5]]);
        // payroll: first checked today at #40 — no previous, so not a mover.
        $check($ids['payroll'], $this->today, 'ok', 40, 'https://aicountly.com/payroll', [[$ids['other'], 'other.example', 2]]);
        // einv: ok a week ago, failed today — never shown as "not ranking".
        $check($ids['einv'], $this->weekAgo, 'ok', null);
        $check($ids['einv'], $this->today, 'failed', null);

        return $ids;
    }

    public function testKeywordTableListsEveryTrackedKeywordWithPreviousCompetitorsAndSearchConsole(): void
    {
        $ids = $this->seedTracker();
        $db  = Database::connect();
        $db->table('reach_seo_tracked_keywords')->insert(['keyword' => 'rk never checked', 'priority' => 'low', 'enabled' => false]);
        $db->table('reach_analytics_connections')->insert(['tenant_id' => 1, 'provider' => 'gsc', 'display_name' => 'GSC', 'site_property' => 'sc-domain:aicountly.com', 'enabled' => true, 'health_status' => 'healthy']);
        $conn = (int) $db->insertID();
        foreach ([0, 1, 40] as $ago) {
            $db->table('reach_search_site_facts')->insert(['connection_id' => $conn, 'site_property' => 'sc-domain:aicountly.com', 'metric_date' => date('Y-m-d', strtotime("-{$ago} days")),
                'page_url' => 'https://aicountly.com/billing', 'query' => 'RK GST billing software', 'device' => '', 'country' => '', 'clicks' => 1, 'impressions' => 30, 'ctr' => 0.03, 'avg_position' => 9]);
        }

        $d = $this->fetch('v1/dashboard/command-centre/rankings/keywords');

        $this->assertTrue($d['available']);
        $this->assertSame($this->today, $d['latest_date']);
        $this->assertSame($this->weekAgo, $d['compared_with']);
        $this->assertSame(['tracked' => 5, 'checked' => 3, 'ranked' => 2, 'top10' => 1, 'failed' => 1, 'not_checked' => 1, 'avg_rank' => 24], $d['summary']);
        $this->assertStringContainsString('not stored', $d['volume_note']);

        $rows = array_column($d['keywords'], null, 'keyword');
        $this->assertSame(['rk gst billing software', 'rk payroll software india', 'rk tally alternative'], array_slice(array_column($d['keywords'], 'keyword'), 0, 3), 'ours by position, then unranked, then unchecked');

        $gst = $rows['rk gst billing software'];
        $this->assertSame([8, 15, 7, true], [$gst['our_rank'], $gst['previous_rank'], $gst['change'], $gst['previous_checked']]);
        $this->assertSame('https://aicountly.com/billing', $gst['our_url']);
        $this->assertSame('billing', $gst['product']);
        $this->assertSame(['name' => 'RK Rival', 'domain' => 'rival.example', 'rank' => 3, 'url' => 'https://rival.example/3'], $gst['best_competitor']);
        $this->assertSame(['RK Rival', 'RK Other'], array_column($gst['competitors'], 'name'));
        $this->assertNull($gst['search_volume']);
        $this->assertSame(['impressions' => 60, 'clicks' => 2, 'avg_position' => 9], $gst['search_console'], 'exact query, case-insensitive, last 28 days only');
        $this->assertStringEndsWith('+05:30', $gst['last_checked_at'], 'IST');
        $this->assertStringContainsString('T06:05:00', $gst['last_checked_at'], '00:35 UTC = 06:05 IST');

        $tally = $rows['rk tally alternative'];
        $this->assertSame(['ok', null, 30, -71], [$tally['status'], $tally['our_rank'], $tally['previous_rank'], $tally['change']]);

        $payroll = $rows['rk payroll software india'];
        $this->assertFalse($payroll['previous_checked']);
        $this->assertNull($payroll['change'], 'no check on the comparison day → no change, not "entered from >100"');

        $einv = $rows['rk e-invoice generator'];
        $this->assertSame('failed', $einv['status']);
        $this->assertSame('HTTP 402 balance', $einv['error']);
        $this->assertNull($einv['our_rank']);
        $this->assertSame($this->weekAgo, $einv['last_ok_date']);

        $never = $rows['rk never checked'];
        $this->assertSame(['not_checked', false, null], [$never['status'], $never['enabled'], $never['last_checked_at']]);

        $comps = array_column($d['competitors'], null, 'name');
        $this->assertSame([2, 2, 4], [$comps['RK Rival']['keywords'], $comps['RK Rival']['top10'], $comps['RK Rival']['avg_rank']]);
        $this->assertSame([['rk gst billing software', 3, 8], ['rk tally alternative', 5, null]],
            array_map(static fn ($p) => [$p['keyword'], $p['rank'], $p['our_rank']], $comps['RK Rival']['placements']));
        $this->assertSame([2, 1, 7], [$comps['RK Other']['keywords'], $comps['RK Other']['top10'], $comps['RK Other']['avg_rank']]);
        $this->assertSame(['rk payroll software india', 'rk gst billing software'], array_column($comps['RK Other']['placements'], 'keyword'));
        $this->assertSame($ids['rival'], $comps['RK Rival']['id']);
    }

    public function testCardCarriesTheSpecificsForItsTooltips(): void
    {
        $this->seedTracker();

        $t = $this->fetch('v1/dashboard/command-centre?fresh=1&sections=rankings')['rankings']['tracker'];

        $this->assertSame([4, 3, 1, 0], [$t['tracked'], $t['keywords_checked'], $t['failed'], $t['not_checked']]);
        $this->assertSame([2, 1, 24, 2], [$t['ranked'], $t['top10'], $t['avg_rank'], $t['avg_rank_over']], 'JSON: 24.0 arrives as 24');
        $this->assertSame([['keyword' => 'rk gst billing software', 'rank' => 8, 'url' => 'https://aicountly.com/billing']], $t['top10_keywords']);
        $this->assertSame(['rk gst billing software', 'rk payroll software india'], array_column($t['ranked_keywords'], 'keyword'));
        $this->assertSame(['rk tally alternative'], $t['unranked_keywords']);
        $this->assertSame([2, 0, 1], [$t['compared'], $t['unchanged'], $t['no_previous']]);
        $this->assertSame(['rk gst billing software'], array_column($t['movers_up'], 'keyword'), 'payroll had no previous check: not a mover');
        $this->assertSame(15, $t['movers_up'][0]['previous_rank']);
        $this->assertSame(['rk tally alternative'], array_column($t['movers_down'], 'keyword'));

        $comps = array_column($t['competitors'], null, 'name');
        $this->assertSame('RK Rival', $t['competitors'][0]['name']);
        $this->assertSame([['keyword' => 'rk gst billing software', 'rank' => 3, 'our_rank' => 8], ['keyword' => 'rk tally alternative', 'rank' => 5, 'our_rank' => null]], $comps['RK Rival']['top10_keywords']);
        $this->assertSame([['keyword' => 'rk payroll software india', 'rank' => 2, 'our_rank' => 40]], $comps['RK Other']['top10_keywords'], 'the #12 placement is not a top-10');
        $this->assertSame(2, $comps['RK Other']['keywords']);
    }

    public function testDrilldownEndpointsUseTheCommandCentrePermission(): void
    {
        $this->fetch('v1/dashboard/command-centre/rankings/keywords', 'analyst');
        $this->fetch('v1/dashboard/command-centre/rankings/ai-answers', 'analyst');
        $this->fetch('v1/dashboard/command-centre/rankings/keywords', 'blog_author', 403);
        $this->fetch('v1/dashboard/command-centre/rankings/ai-answers', 'blog_author', 403);
        $this->assertSame(401, $this->withHeaders([])->call('GET', 'v1/dashboard/command-centre/rankings/keywords')->response()->getStatusCode());
    }

    public function testEmptyTrackerSaysSo(): void
    {
        $d = $this->fetch('v1/dashboard/command-centre/rankings/keywords');
        $this->assertFalse($d['available']);
        $this->assertSame([], $d['keywords']);
        $this->assertNotEmpty($d['note']);

        $a = $this->fetch('v1/dashboard/command-centre/rankings/ai-answers');
        $this->assertFalse($a['available']);
        $this->assertNull($a['mention_rate'], 'nothing asked is not 0%');
    }

    private function registry(): AssistantRegistry
    {
        return new AssistantRegistry([
            new RkFakeAssistant('gemini', static fn (string $q): AssistantAnswer => new AssistantAnswer('gemini', 'gemini-test', "Top picks:\n1. **Zoho Books** – cloud\n2. **AICOUNTLY** – AI-first GST\n3. TallyPrime", [], 100)),
            new RkFakeAssistant('openai', static fn (string $q): AssistantAnswer => new AssistantAnswer('openai', 'gpt-test', "- Tally: the default\n- Vyapar: billing for shops", [], 80)),
        ], ['gemini', 'openai']);
    }

    public function testAiAnswersShowWhichQuestionsNamedUsAndCompetitors(): void
    {
        $db = Database::connect();
        $db->query("UPDATE reach_ai_visibility_prompts SET status = 'paused'");
        $db->query("UPDATE reach_ai_visibility_prompts SET status = 'active' WHERE name IN ('in-retail-billing', 'in-ai-accounting')");
        $svc = new AiVisibilityService(null, $this->registry(), static function (int $id): void {
        });
        $svc->startBatch('scheduled', null, true);

        $a = $this->fetch('v1/dashboard/command-centre/rankings/ai-answers');
        $s = $svc->summary(28)['overall'];

        $this->assertTrue($a['available']);
        $this->assertTrue($a['measured']);
        $this->assertSame(['answers' => 4, 'counted' => $s['brand_answers_counted'], 'brand_mentions' => $s['brand_mentions'], 'failed' => 0], $a['totals'], 'same counting as the headline');
        $this->assertSame($s['brand_mention_rate'], $a['mention_rate']);
        $this->assertCount(2, $a['prompts']);
        $p = $a['prompts'][0];
        $this->assertNotEmpty($p['question']);
        $prov = array_column($p['providers'], null, 'provider');
        $this->assertSame([1, 1, 1], [$prov['gemini']['answers'], $prov['gemini']['counted'], $prov['gemini']['brand_mentions']]);
        $this->assertSame('gemini-test', $prov['gemini']['model']);
        $this->assertSame(0, $prov['openai']['brand_mentions']);
        $this->assertContains('Tally', array_column($prov['openai']['competitors'], 'name'));
        $this->assertStringEndsWith('+05:30', $prov['openai']['last_asked_at']);
    }

    public function testAnswersOnlyToQuestionsNamingUsAreNotMeasuredRatherThanZero(): void
    {
        $db = Database::connect();
        $db->query("UPDATE reach_ai_visibility_prompts SET status = 'paused'");
        $db->query("UPDATE reach_ai_visibility_prompts SET status = 'active' WHERE name = 'brand-what-is-aicountly'");
        (new AiVisibilityService(null, $this->registry(), static function (int $id): void {
        }))->startBatch('scheduled', null, true);

        $v = $this->fetch('v1/dashboard/command-centre?fresh=1&sections=rankings')['rankings']['visibility'];
        $this->assertTrue($v['available']);
        $this->assertSame(2, $v['answers']);
        $this->assertSame(0, $v['brand_answers_counted']);
        $this->assertFalse($v['measured']);
        $this->assertNull($v['mention_rate'], 'not 0%');

        $a = $this->fetch('v1/dashboard/command-centre/rankings/ai-answers');
        $this->assertFalse($a['measured']);
        $this->assertNull($a['mention_rate']);
        $this->assertSame(0, $a['prompts'][0]['counted']);
    }
}

/** A canned assistant for the AI-visibility drill-down. */
final class RkFakeAssistant implements AssistantClient
{
    /** @var callable(string): AssistantAnswer */
    private $answer;

    public function __construct(private string $k, callable $answer)
    {
        $this->answer = $answer;
    }

    public function key(): string
    {
        return $this->k;
    }

    public function label(): string
    {
        return ucfirst($this->k);
    }

    public function model(): string
    {
        return $this->k . '-test';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function ask(string $question): AssistantAnswer
    {
        if ($this->k === '') {
            throw new AssistantException('no key');
        }

        return ($this->answer)($question);
    }
}

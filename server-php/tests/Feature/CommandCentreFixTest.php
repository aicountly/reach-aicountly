<?php

namespace Tests\Feature;

use App\Libraries\Dashboard\CommandCentreService;
use App\Libraries\Dashboard\RecommendationFixer;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * "Let the bot fix it" on the Command centre's "What to do next" cards
 * (POST v1/dashboard/command-centre/fix) and the bot's own pass in AUTO mode.
 */
final class CommandCentreFixTest extends ApiTestCase
{
    private int $conn;

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        ReachConfig::flush();
        foreach (CommandCentreService::SECTIONS as $section) {
            \Config\Services::cache()->delete('reach_cc_' . $section);
        }
        $db = Database::connect();
        $db->table('reach_analytics_connections')->insert([
            'tenant_id' => 1, 'provider' => 'gsc', 'display_name' => 'GSC', 'site_property' => 'sc-domain:aicountly.com', 'enabled' => true, 'health_status' => 'healthy',
        ]);
        $this->conn = (int) $db->insertID();
    }

    private function fact(string $page, string $query, int $impressions, int $clicks, float $position, int $days = 10): void
    {
        for ($i = 0; $i < $days; $i++) {
            Database::connect()->table('reach_search_site_facts')->insert([
                'connection_id' => $this->conn, 'site_property' => 'sc-domain:aicountly.com', 'metric_date' => date('Y-m-d', strtotime("-{$i} days")),
                'page_url' => $page, 'query' => $query, 'device' => '', 'country' => '',
                'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions > 0 ? $clicks / $impressions : 0, 'avg_position' => $position,
            ]);
        }
    }

    /** @return array<string, array<string, mixed>> cards by id */
    private function cards(): array
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/dashboard/command-centre?fresh=1&sections=health,recommendations');
        $this->assertSame(200, $res->response()->getStatusCode());

        $rec = json_decode((string) $res->getJSON(), true)['data']['recommendations'];
        $this->assertArrayHasKey('cards', $rec, json_encode($rec));

        return array_column($rec['cards'], null, 'id');
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function fix(string $cardId): array
    {
        $res  = $this->withHeaders($this->authAs('super_admin'))->withBodyFormat('json')->call('POST', 'v1/dashboard/command-centre/fix', ['card_id' => $cardId]);
        $body = json_decode((string) $res->getJSON(), true);

        return [$res->response()->getStatusCode(), $body['data'] ?? $body];
    }

    public function testASearchOpportunityBecomesTheBlogRoutinesNextTopic(): void
    {
        $this->fact('https://aicountly.com/blogs/gstr-3b-guide', 'gstr 3b due date for september 2026', 36, 0, 5.6);
        $id = 'push-' . md5('gstr 3b due date for september 2026');

        $card = $this->cards()[$id];
        $this->assertSame('v1/dashboard/command-centre/fix', $card['bot_fix']['endpoint']);
        $this->assertSame(['card_id' => $id], $card['bot_fix']['body']);
        $this->assertNull($card['fix_state']);

        [$http, $data] = $this->fix($id);
        $this->assertSame(200, $http, json_encode($data));
        $this->assertSame('queued', $data['status']);
        $this->assertSame('content_base', $data['ref_type']);

        $entry = Database::connect()->table('reach_content_base_entries')->where('entry_key', $data['ref_id'])->get()->getRowArray();
        $this->assertSame('signal', $entry['origin']);
        $this->assertSame('planned', $entry['status']);
        $this->assertSame(100, (int) $entry['priority'], 'first in the blog routine\'s queue');
        $this->assertStringContainsString('position 5.6', $entry['brief']);
        $this->assertStringContainsString('https://aicountly.com/blogs/gstr-3b-guide', $entry['brief'], 'links to the page that already ranks');

        // The card now says the bot is on it, and a second press does nothing new.
        $this->assertTrue($this->cards()[$id]['fix_state']['active']);
        [, $again] = $this->fix($id);
        $this->assertSame('already', $again['status']);
        $this->assertSame(1, Database::connect()->table('reach_content_base_entries')->where('origin', 'signal')->countAllResults());
    }

    public function testALowClickReachPageGetsASameUrlRewriteAimedAtItsTitle(): void
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'content_type' => 'blog', 'title' => 'GST credit note under section 34', 'slug' => 'gst-credit-note-section-34', 'workflow_status' => 'live',
        ]);
        $itemId = (int) $db->insertID();
        $url    = 'https://aicountly.com/blogs/gst-credit-note-section-34';
        $this->fact($url, 'gst credit note time limit', 30, 0, 6.0);
        $this->fact('https://aicountly.com/about', 'aicountly team', 30, 0, 3.0);
        $id = 'retitle-' . md5($url);

        $cards = $this->cards();
        $this->assertArrayHasKey('bot_fix', $cards[$id]);
        $other = $cards['retitle-' . md5('https://aicountly.com/about')];
        $this->assertArrayNotHasKey('bot_fix', $other, 'Reach did not publish that page, so the bot cannot rewrite it');
        $this->assertStringContainsString('aicountly.com code', $other['detail']);

        [$http, $data] = $this->fix($id);
        $this->assertSame(200, $http, json_encode($data));
        $this->assertSame('regeneration', $data['ref_type']);

        $regen = $db->table('reach_content_regenerations')->where('id', (int) $data['ref_id'])->get()->getRowArray();
        $this->assertSame($itemId, (int) $regen['content_item_id']);
        $this->assertSame('queued', $regen['status']);
        $this->assertSame('t', $regen['was_live']);
        $why = implode(' ', json_decode($regen['reasons'], true));
        $this->assertStringContainsString('300 impressions but 0 clicks', $why);
        $this->assertStringContainsString('gst credit note time limit', $why, 'the queries the page shows for steer the new title');
    }

    public function testInAutoModeTheBotFixesCardsItselfWithinItsDailyLimit(): void
    {
        $this->fact('https://aicountly.com/blogs/a', 'tds on rent threshold', 40, 0, 6.0);
        $this->fact('https://aicountly.com/blogs/b', 'gst late fee calculator', 30, 0, 8.0);

        $db = Database::connect();
        $this->assertArrayHasKey('skipped', (new RecommendationFixer())->autoFix(), 'confirm mode (the default) leaves cards to a person');

        $db->table('reach_bot_settings')->insert(['mode' => 'auto', 'allowed_auto_actions' => '[]']);
        $out = (new RecommendationFixer())->autoFix();
        $this->assertSame(1, $db->table('reach_content_base_entries')->where('origin', 'signal')->countAllResults(), 'search_topics_per_day defaults to 1');
        $this->assertSame(0, $out['budget_left']['search_topic']);
        $this->assertSame('bot', $db->table('reach_recommendation_fixes')->get()->getRowArray()['actor']);

        (new RecommendationFixer())->autoFix();
        $this->assertSame(1, $db->table('reach_content_base_entries')->where('origin', 'signal')->countAllResults(), 'the next hour does not exceed the day\'s limit');
    }

    public function testAQuietRoutineCardOffersRunNow(): void
    {
        $card = $this->cards()['routine-workflow_guide'] ?? null;
        $this->assertNotNull($card, 'no submissions yet → quiet');
        $this->assertSame('v1/bot/activities/routine_reach_workflow_guides/run', $card['action']['endpoint']);
    }

    public function testAnUnknownCardIsRefused(): void
    {
        [$http] = $this->fix('push-' . md5('nothing like this'));
        $this->assertSame(404, $http);
    }
}

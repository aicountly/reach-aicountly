<?php

declare(strict_types=1);

namespace Tests\Feature\Ads;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * v1/ads/* end to end on PostgreSQL: the register of manually placed
 * campaigns, the launch plan, daily results by hand and by CSV import,
 * sign-ups from the my.aicountly.com report, lead attribution by UTM and the
 * summary the dashboard reads. Nothing is fetched from an ads platform and no
 * figure is invented: absent data stays null.
 *
 * @internal
 */
final class AdsApiTest extends ApiTestCase
{
    private const LANDING = 'https://aicountly.com/free-accounting-software';

    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->authAs('super_admin');
    }

    private function json($res): array
    {
        return json_decode((string) $res->getJSON(), true);
    }

    private function api(string $method, string $url, array $body = [], ?array $headers = null)
    {
        return $this->withHeaders($headers ?? $this->admin)->withBodyFormat('json')->call($method, $url, $body);
    }

    private function role(string $slug, array $permissions): array
    {
        $id = $this->ensureRole($slug);
        Database::connect()->table('reach_roles')->where('id', $id)->update(['permissions' => json_encode($permissions)]);

        return $this->authAs($slug);
    }

    private function campaign(array $over = []): array
    {
        $res = $this->api('POST', 'v1/ads/campaigns', $over + [
            'platform' => 'google_search', 'name' => 'Soft launch - Search', 'daily_budget_inr' => 600,
            'start_date' => '2026-10-18', 'landing_url' => self::LANDING, 'status' => 'live',
            'creatives' => [['id' => 'gs-rsa-01', 'label' => 'RSA 1']],
        ]);
        $this->assertSame(201, $res->response()->getStatusCode(), (string) $res->getJSON());

        return $this->json($res)['data'];
    }

    public function testRegisterACampaignWithTrackingLinks(): void
    {
        $c = $this->campaign();
        $this->assertSame('google', $c['utm_source']);
        $this->assertSame('cpc', $c['utm_medium']);
        $this->assertSame('soft-launch-2026-10', $c['utm_campaign']);
        $this->assertSame(600.0, (float) $c['daily_budget_inr']);
        $this->assertSame([['creative_id' => 'gs-rsa-01', 'url' => self::LANDING . '?utm_source=google&utm_medium=cpc&utm_campaign=soft-launch-2026-10&utm_content=gs-rsa-01']], $c['tracking_links']);

        $bad = $this->api('POST', 'v1/ads/campaigns', ['platform' => 'google_search', 'name' => 'X', 'start_date' => '2026-10-18', 'landing_url' => 'https://example.com/']);
        $this->assertSame(422, $bad->response()->getStatusCode());

        $upd = $this->api('PUT', 'v1/ads/campaigns/' . $c['id'], ['status' => 'paused', 'external_ref' => 'Aicountly | Search | Launch']);
        $this->assertSame('paused', $this->json($upd)['data']['status']);
        $this->assertSame('Aicountly | Search | Launch', $this->json($upd)['data']['external_ref']);

        $list = $this->json($this->api('GET', 'v1/ads/campaigns'))['data'];
        $this->assertSame(1, $list['total']);

        $this->api('DELETE', 'v1/ads/campaigns/' . $c['id']);
        $this->assertSame(0, $this->json($this->api('GET', 'v1/ads/campaigns'))['data']['total'], 'archived campaigns leave the register');
    }

    public function testTheLaunchPlanRegistersFourPlannedCampaignsOnce(): void
    {
        $res = $this->api('POST', 'v1/ads/campaigns/launch-plan', ['start_date' => '2026-10-18']);
        $this->assertSame(201, $res->response()->getStatusCode());
        $this->assertCount(4, $this->json($res)['data']['created']);

        $rows = Database::connect()->query('SELECT platform, daily_budget_inr, status, utm_source, utm_medium FROM reach_ad_campaigns ORDER BY id')->getResultArray();
        $this->assertSame(['google_search', 'youtube', 'meta', 'linkedin'], array_column($rows, 'platform'));
        $this->assertSame(['600.00', '300.00', '300.00', '800.00'], array_column($rows, 'daily_budget_inr'));
        $this->assertSame(['planned'], array_values(array_unique(array_column($rows, 'status'))));
        $this->assertSame(['google', 'youtube', 'meta', 'linkedin'], array_column($rows, 'utm_source'));
        $this->assertSame(0, (int) Database::connect()->query('SELECT COUNT(*) AS n FROM reach_ad_results')->getRowArray()['n'], 'a plan is not spend');

        $again = $this->json($this->api('POST', 'v1/ads/campaigns/launch-plan', []))['data'];
        $this->assertSame([], $again['created']);
        $this->assertCount(4, $again['already_registered']);

        $summary = $this->json($this->api('GET', 'v1/ads/summary?from=2026-10-18&to=2026-10-18'))['data'];
        $this->assertSame(2000.0, (float) $summary['budget']['planned_daily_inr']);
        $this->assertNull($summary['totals']['spend_inr'], 'no results entered: spend is unknown, not zero');
        $this->assertFalse($summary['has_results']);
        $this->assertFalse($summary['api_connected']);
    }

    public function testManualResultsAndValidation(): void
    {
        $c = $this->campaign();
        $url = 'v1/ads/campaigns/' . $c['id'] . '/results';
        $res = $this->api('PUT', $url, ['result_date' => '2026-10-18', 'spend_inr' => '598.40', 'impressions' => 1234, 'clicks' => 41, 'conversions' => 3]);
        $this->assertSame(201, $res->response()->getStatusCode());
        $this->assertSame('manual', $this->json($res)['data']['metrics_source']);
        $this->assertSame(200, $this->api('PUT', $url, ['result_date' => '2026-10-18', 'clicks' => 42])->response()->getStatusCode());
        $this->assertSame(42, (int) Database::connect()->table('reach_ad_results')->get()->getRowArray()['clicks']);
        $this->assertSame('598.40', Database::connect()->table('reach_ad_results')->get()->getRowArray()['spend_inr'], 'other figures are kept');

        foreach ([['result_date' => '2999-01-01', 'clicks' => 1], ['result_date' => '2026-10-18', 'clicks' => -1], ['result_date' => '2026-10-18', 'clicks' => 1.5], ['result_date' => '2026-10-18']] as $bad) {
            $this->assertSame(422, $this->api('PUT', $url, $bad)->response()->getStatusCode(), json_encode($bad));
        }
    }

    public function testCsvImportDryRunStoreReimportAndSignups(): void
    {
        $search = $this->campaign(['external_ref' => 'Aicountly Search Launch']);
        $meta = $this->campaign(['platform' => 'meta', 'name' => 'Soft launch FB+IG', 'daily_budget_inr' => 300, 'creatives' => [['id' => 'fb-reel-01']]]);

        $csv = "Campaign report\nDay,Campaign,Currency code,Cost,Impr.,Clicks,Conversions\n"
            . "2026-10-18,Aicountly Search Launch,INR,598.40,1234,41,3\n"
            . "2026-10-18,Aicountly Search Launch,INR,1.60,6,1,0\n"
            . "2026-10-19,Aicountly Search Launch,INR,612.00,1100,38,2\n"
            . "2026-10-19,Someone else's campaign,INR,10,10,1,0\n"
            . "Total: Campaigns,,,1222,2350,81,5\n";

        $dry = $this->json($this->api('POST', 'v1/ads/import', ['format' => 'auto', 'csv' => $csv, 'dry_run' => true]))['data'];
        $this->assertSame('google_ads', $dry['format']);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(0, $dry['stored_days']);
        $this->assertSame(1, $dry['unmatched_total']);
        $this->assertSame(1, $dry['skipped']);
        $this->assertSame(1212.0, (float) $dry['campaigns'][0]['spend_inr']);
        $this->assertSame(0, (int) Database::connect()->query('SELECT COUNT(*) AS n FROM reach_ad_results')->getRowArray()['n'], 'a dry run stores nothing');

        $real = $this->json($this->api('POST', 'v1/ads/import', ['format' => 'google_ads', 'csv' => $csv]))['data'];
        $this->assertSame(2, $real['stored_days']);
        $again = $this->json($this->api('POST', 'v1/ads/import', ['format' => 'google_ads', 'csv' => $csv]))['data'];
        $this->assertSame(2, $again['stored_days']);
        $rows = Database::connect()->query('SELECT result_date, spend_inr, clicks, metrics_source FROM reach_ad_results ORDER BY result_date')->getResultArray();
        $this->assertSame([['result_date' => '2026-10-18', 'spend_inr' => '600.00', 'clicks' => '42', 'metrics_source' => 'csv:google_ads'],
            ['result_date' => '2026-10-19', 'spend_inr' => '612.00', 'clicks' => '38', 'metrics_source' => 'csv:google_ads']], $rows, 're-importing the file changes nothing');

        $signups = "date,utm_source,utm_medium,utm_campaign,utm_content,signups,with_click_id\n"
            . "2026-10-18,google,cpc,soft-launch-2026-10,gs-rsa-01,2,2\n"
            . "2026-10-18,google,cpc,soft-launch-2026-10,,1,0\n"
            . "2026-10-18,meta,paid_social,soft-launch-2026-10,fb-reel-01,1,1\n"
            . "2026-10-18,,,,,7,0\n"
            . "2026-10-18,newsletter,email,oct,,1,0\n";
        $s = $this->json($this->api('POST', 'v1/ads/import', ['format' => 'aicountly_signups', 'csv' => $signups]))['data'];
        $this->assertSame(7, $s['unattributed_signups']);
        $this->assertSame(1, $s['unmatched_total']);
        $this->assertSame(2, $s['stored_days']);

        // A lead from Reach's lead capture with the Search UTM set, one outside the dates, one from elsewhere.
        $db = Database::connect();
        $db->table('reach_leads')->insert(['name' => 'Asha', 'email' => 'asha@example.test', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'soft-launch-2026-10', 'utm_content' => 'gs-rsa-01', 'created_at' => '2026-10-18 11:00:00+05:30']);
        $db->table('reach_leads')->insert(['name' => 'Old', 'email' => 'old@example.test', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'soft-launch-2026-10', 'created_at' => '2026-09-01 11:00:00+05:30']);
        $db->table('reach_leads')->insert(['name' => 'Ravi', 'email' => 'ravi@example.test', 'utm_source' => 'linkedin', 'utm_campaign' => 'soft-launch-2026-10', 'created_at' => '2026-10-18 12:00:00+05:30']);

        $sum = $this->json($this->api('GET', 'v1/ads/summary?from=2026-10-18&to=2026-10-19'))['data'];
        $this->assertTrue($sum['has_results']);
        $this->assertSame(1212.0, (float) $sum['totals']['spend_inr']);
        $this->assertSame(80, $sum['totals']['clicks']);
        $this->assertSame(4, $sum['totals']['signups']);
        $this->assertSame(1, $sum['totals']['leads']);
        $this->assertEquals(round(1212 / 80, 2), $sum['totals']['cpc_inr']);
        $this->assertEquals(303, $sum['totals']['cost_per_signup_inr']);
        $byId = array_column($sum['campaigns'], null, 'id');
        $this->assertSame(3, $byId[$search['id']]['signups']);
        $this->assertSame([['utm_content' => 'gs-rsa-01', 'leads' => 1]], $byId[$search['id']]['leads_by_creative']);
        $this->assertNull($byId[$meta['id']]['spend_inr'], 'Meta has sign-ups but no spend entered: spend stays unknown');
        $this->assertSame(1, $byId[$meta['id']]['signups']);
        $this->assertNull($byId[$meta['id']]['cost_per_signup_inr']);
        $this->assertStringContainsString('not connected', $sum['source']);

        $bad = $this->api('POST', 'v1/ads/import', ['format' => 'meta', 'csv' => "Day,Campaign name,Amount spent (USD)\n2026-10-18,X,1\n"]);
        $this->assertSame(422, $bad->response()->getStatusCode());
    }

    public function testPermissions(): void
    {
        $social = $this->role('ads_social_viewer', ['social.view']);
        $this->assertSame(200, $this->api('GET', 'v1/ads/meta', [], $social)->response()->getStatusCode());
        $this->assertSame(200, $this->api('GET', 'v1/ads/campaigns', [], $social)->response()->getStatusCode());
        $this->assertSame(403, $this->api('POST', 'v1/ads/campaigns', ['platform' => 'meta'], $social)->response()->getStatusCode());
        $this->assertSame(403, $this->api('POST', 'v1/ads/import', ['csv' => 'x'], $social)->response()->getStatusCode());

        $dash = $this->role('ads_dashboard_viewer', ['dashboard.view']);
        $this->assertSame(200, $this->api('GET', 'v1/ads/summary', [], $dash)->response()->getStatusCode());
        $this->assertSame(403, $this->api('GET', 'v1/ads/campaigns', [], $dash)->response()->getStatusCode());

        $none = $this->role('ads_nobody', ['blog.view']);
        $this->assertSame(403, $this->api('GET', 'v1/ads/summary', [], $none)->response()->getStatusCode());
        $this->assertSame(401, $this->withHeaders(['Accept' => 'application/json'])->call('GET', 'v1/ads/summary')->response()->getStatusCode());
    }

    public function testUtmLink(): void
    {
        $res = $this->json($this->api('POST', 'v1/ads/utm-link', ['platform' => 'youtube', 'creative_id' => 'yt-reel-01']))['data'];
        $this->assertSame(self::LANDING . '?utm_source=youtube&utm_medium=video&utm_campaign=soft-launch-2026-10&utm_content=yt-reel-01', $res['url']);
        $this->assertSame(422, $this->api('POST', 'v1/ads/utm-link', ['platform' => 'youtube', 'landing_url' => 'https://evil.example/'])->response()->getStatusCode());
    }
}

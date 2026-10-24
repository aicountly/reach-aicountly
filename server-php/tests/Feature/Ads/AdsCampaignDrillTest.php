<?php

declare(strict_types=1);

namespace Tests\Feature\Ads;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Ads tiles → campaign → day: GET v1/ads/campaigns/{id}/results?from=&to=
 * returns the day rows the summary summed for that campaign, and leads per
 * day under the summary's attribution rule, so the totals add up.
 *
 * @internal
 */
final class AdsCampaignDrillTest extends ApiTestCase
{
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->authAs('super_admin');
    }

    private function api(string $method, string $url, array $body = []): array
    {
        $res = $this->withHeaders($this->admin)->withBodyFormat('json')->call($method, $url, $body);
        $this->assertLessThan(300, $res->response()->getStatusCode(), $url . ' ' . (string) $res->getJSON());

        return json_decode((string) $res->getJSON(), true)['data'];
    }

    private function campaign(array $over): array
    {
        return $this->api('POST', 'v1/ads/campaigns', $over + [
            'platform' => 'google_search', 'name' => 'Search', 'daily_budget_inr' => 600, 'start_date' => '2026-10-01',
            'landing_url' => 'https://aicountly.com/free-accounting-software', 'status' => 'live',
        ]);
    }

    public function testDayRowsAndLeadsAddUpToTheSummaryRow(): void
    {
        $search = $this->campaign([]);
        $meta = $this->campaign(['platform' => 'meta', 'name' => 'Meta', 'daily_budget_inr' => 300, 'status' => 'paused']);
        $li = $this->campaign(['platform' => 'linkedin', 'name' => 'LinkedIn', 'daily_budget_inr' => 800]);

        foreach ([['2026-10-10', 100.5, 20, 2], ['2026-10-11', 200, 30, null], ['2026-09-20', 999, 99, 9]] as [$day, $spend, $clicks, $signups]) {
            $body = ['result_date' => $day, 'spend_inr' => $spend, 'clicks' => $clicks];
            if ($signups !== null) {
                $body['signups'] = $signups;
            }
            $this->api('PUT', "v1/ads/campaigns/{$search['id']}/results", $body);
        }
        $this->api('PUT', "v1/ads/campaigns/{$meta['id']}/results", ['result_date' => '2026-10-10', 'spend_inr' => 50]);

        $db = Database::connect();
        $lead = static fn (string $at, string $source = 'google', string $medium = 'cpc') => $db->table('reach_leads')->insert([
            'name' => 'L', 'utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => 'soft-launch-2026-10', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $lead('2026-10-10 10:00:00');
        $lead('2026-10-10 15:00:00');
        $lead('2026-10-12 09:00:00'); // a day with leads but no results row
        $lead('2026-09-25 09:00:00'); // before the campaign started
        $lead('2026-10-10 11:00:00', 'linkedin', 'paid_social');

        $period = 'from=2026-10-05&to=2026-10-20';
        $summary = $this->api('GET', "v1/ads/summary?{$period}");
        $row = array_values(array_filter($summary['campaigns'], static fn (array $c): bool => $c['id'] === (int) $search['id']))[0];

        $results = $this->api('GET', "v1/ads/campaigns/{$search['id']}/results?{$period}");
        $this->assertSame(['2026-10-11', '2026-10-10'], array_column($results['items'], 'result_date'));
        $this->assertEqualsWithDelta($row['spend_inr'], array_sum(array_map('floatval', array_column($results['items'], 'spend_inr'))), 0.001);
        $this->assertSame($row['clicks'], array_sum(array_map('intval', array_column($results['items'], 'clicks'))));
        $this->assertSame($row['signups'], array_sum(array_map('intval', array_filter(array_column($results['items'], 'signups'), static fn ($v) => $v !== null))));
        $this->assertSame(['2026-10-10' => 2, '2026-10-12' => 1], $results['leads_by_day']);
        $this->assertSame($row['leads'], array_sum($results['leads_by_day']));

        // Without a period: every row; leads from the campaign's start.
        $all = $this->api('GET', "v1/ads/campaigns/{$search['id']}/results");
        $this->assertSame(3, $all['total']);
        $this->assertSame(3, array_sum($all['leads_by_day']));

        // Live budget = the live campaigns' daily budgets in the summary's campaign list.
        $live = array_filter($summary['campaigns'], static fn (array $c): bool => $c['status'] === 'live');
        $this->assertEqualsCanonicalizing([(int) $search['id'], (int) $li['id']], array_column($live, 'id'));
        $this->assertEqualsWithDelta($summary['budget']['live_daily_inr'], array_sum(array_column($live, 'daily_budget_inr')), 0.001);

        $bad = $this->withHeaders($this->admin)->call('GET', "v1/ads/campaigns/{$search['id']}/results?from=2026-10-20&to=2026-10-05");
        $this->assertSame(422, $bad->response()->getStatusCode());
    }
}

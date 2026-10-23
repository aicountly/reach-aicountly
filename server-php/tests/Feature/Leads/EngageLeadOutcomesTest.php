<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Libraries\Leads\LeadOutcomeSummary;
use Config\Database;
use Tests\Support\ApiTestCase;
use Tests\Support\EnvOverride;

/**
 * POST v1/inbound/engage/lead-outcomes end to end on PostgreSQL: token guard,
 * idempotency by event_id, lead matching (reach_lead_id → lead_code → email hash),
 * attribution, the forward-only lead stage, LeadOutcomeSummary and the staff APIs.
 *
 * @internal
 */
final class EngageLeadOutcomesTest extends ApiTestCase
{
    use EnvOverride;

    private const TOKEN = 'test-engage-outcomes-token-0123456789abcdef';
    private const URL   = 'v1/inbound/engage/lead-outcomes';

    private int $campaignId;
    private int $otherCampaignId;
    private int $landingLead;
    private int $codeLead;
    private int $emailLead;
    private int $touchLead;
    private int $contentId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $db->table('reach_campaigns')->insert(['name' => 'Diwali GST push', 'campaign_type' => 'landing', 'status' => 'approved', 'tenant_id' => 0]);
        $this->campaignId = (int) $db->insertID();
        $db->table('reach_campaigns')->insert(['name' => 'Webinar series', 'campaign_type' => 'webinar', 'status' => 'approved', 'tenant_id' => 0]);
        $this->otherCampaignId = (int) $db->insertID();

        $lead = static function (array $row) use ($db): int {
            $db->table('reach_leads')->insert($row + ['priority' => 'normal']);

            return (int) $db->insertID();
        };
        $this->landingLead = $lead(['name' => 'Asha Rao', 'email' => 'asha@example.test', 'campaign_id' => $this->campaignId, 'utm_source' => 'google', 'source_kind' => 'landing']);
        $this->codeLead    = $lead(['name' => 'Ravi K', 'email' => 'ravi@example.test', 'engage_lead_code' => 'LD-000200', 'source_kind' => 'form']);
        $this->emailLead   = $lead(['name' => 'Meera S', 'email' => 'meera@example.test', 'ref_code' => 'SLR-000123']);
        $this->touchLead   = $lead(['name' => 'Touch P', 'email' => 'touch@example.test']);

        $db->query("INSERT INTO reach_content_identities (tenant_id, content_type, source_id, canonical_url) VALUES (1, 'blog', 77, 'https://www.aicountly.com/blog/gst-returns') RETURNING id");
        $this->contentId = (int) $db->query("SELECT id FROM reach_content_identities WHERE source_id = 77 AND content_type = 'blog'")->getRowArray()['id'];
        $db->query(
            "INSERT INTO reach_attribution_touchpoints (tenant_id, utm_source, content_identity_id, campaign_id, channel, touchpoint_type)
             VALUES (1, 'linkedin', ?, ?, 'social', 'click')",
            [$this->contentId, $this->otherCampaignId],
        );
        $tp = (int) $db->query('SELECT MAX(id) AS id FROM reach_attribution_touchpoints')->getRowArray()['id'];
        $db->query(
            "INSERT INTO reach_attribution_conversion_links (tenant_id, lead_id, first_touchpoint_id, last_touchpoint_id, conversion_type, converted_at)
             VALUES (1, ?, ?, ?, 'lead', NOW())",
            [$this->touchLead, $tp, $tp],
        );
    }

    private function send(array $events, ?string $token = self::TOKEN)
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['X-Engage-Token'] = $token;
        }

        return $this->withHeaders($headers)->withBodyFormat('json')->call('POST', self::URL, ['events' => $events]);
    }

    private static function data($res): array
    {
        return json_decode((string) $res->getJSON(), true);
    }

    private static function event(string $id, array $over = []): array
    {
        return array_merge([
            'event_id' => $id, 'lead_code' => 'LD-X-' . $id, 'reach_lead_id' => null, 'email_sha256' => null,
            'stage' => 'qualified', 'sales_status' => 'open', 'product' => 'books', 'value_inr' => null,
            'occurred_at' => '2026-10-16T10:00:00+05:30', 'seller_code' => null, 'campaign_code' => null,
            'source_portal' => 'reach.aicountly.org',
        ], $over);
    }

    public function testTokenGuard(): void
    {
        $this->assertSame(401, $this->send([self::event('e1')], null)->response()->getStatusCode());
        $this->assertSame(401, $this->send([self::event('e1')], 'wrong-token-wrong-token-wrong-token-xx')->response()->getStatusCode());
        $res = $this->withEnv('REACH_INBOUND_ENGAGE_TOKEN', null, fn () => $this->send([self::event('e1')]));
        $this->assertSame(503, $res->response()->getStatusCode());
        $this->assertSame(0, (int) Database::connect()->query('SELECT COUNT(*) AS n FROM reach_lead_outcomes')->getRowArray()['n']);
    }

    public function testBodyShapeIsValidated(): void
    {
        $res = $this->withHeaders(['X-Engage-Token' => self::TOKEN])->withBodyFormat('json')->call('POST', self::URL, ['nope' => 1]);
        $this->assertSame(422, $res->response()->getStatusCode());
        $this->assertSame(422, $this->send([])->response()->getStatusCode());
    }

    public function testMatchingAttributionAndIdempotency(): void
    {
        $events = [
            self::event('evt-1', ['reach_lead_id' => $this->landingLead, 'stage' => 'converted', 'sales_status' => 'won', 'value_inr' => '20000.00']),
            self::event('evt-2', ['lead_code' => 'LD-000200', 'stage' => 'demo', 'campaign_code' => 'REACH-' . $this->otherCampaignId]),
            self::event('evt-3', ['lead_code' => 'LD-NEW-1', 'email_sha256' => hash('sha256', 'meera@example.test'), 'seller_code' => 'SLR-000123', 'stage' => 'proposal_sent']),
            self::event('evt-4', ['reach_lead_id' => $this->touchLead, 'stage' => 'converted', 'value_inr' => '5000']),
            self::event('evt-5', ['lead_code' => 'LD-UNKNOWN', 'campaign_code' => 'REACH-' . $this->campaignId, 'stage' => 'lost']),
            self::event('evt-6', ['stage' => 'Bad Stage']),
        ];
        $res = $this->send($events);
        $this->assertSame(200, $res->response()->getStatusCode());
        $d = self::data($res)['data'];
        $this->assertSame([6, 5, 0, 1, 0], [$d['received'], $d['accepted'], $d['duplicate'], $d['rejected'], $d['failed']]);
        $by = array_column($d['results'], null, 'event_id');
        $this->assertSame(['reach_lead_id', 'lead_campaign'], [$by['evt-1']['match'], $by['evt-1']['attribution']]);
        $this->assertSame(['engage_lead_code', $this->codeLead], [$by['evt-2']['match'], $by['evt-2']['lead_id']]);
        $this->assertSame(['email_sha256', $this->emailLead], [$by['evt-3']['match'], $by['evt-3']['lead_id']]);
        $this->assertSame('touchpoint', $by['evt-4']['attribution']);
        $this->assertSame(['unmatched', 'campaign_code'], [$by['evt-5']['match'], $by['evt-5']['attribution']]);
        $this->assertSame('rejected', $by['evt-6']['status']);
        $this->assertStringContainsString('stage', $by['evt-6']['error']);

        $db   = Database::connect();
        $rows = array_column($db->query('SELECT * FROM reach_lead_outcomes ORDER BY id')->getResultArray(), null, 'event_id');
        $this->assertSame([(string) $this->campaignId, 'google'], [$rows['evt-1']['campaign_id'], $rows['evt-1']['source']]);
        $this->assertSame('20000.00', $rows['evt-1']['value_inr']);
        $this->assertSame((string) $this->otherCampaignId, $rows['evt-2']['campaign_id'], 'Engage campaign_code used when the lead has none');
        $this->assertSame('form', $rows['evt-2']['source']);
        $this->assertSame('seller', $rows['evt-3']['source']);
        $this->assertSame([(string) $this->otherCampaignId, (string) $this->contentId, 'linkedin'],
            [$rows['evt-4']['campaign_id'], $rows['evt-4']['content_identity_id'], $rows['evt-4']['source']]);
        $this->assertNull($rows['evt-5']['lead_id']);

        // Lead rows: latest stage, code learned from the email match.
        $meera = $db->table('reach_leads')->where('id', $this->emailLead)->get()->getRowArray();
        $this->assertSame(['proposal_sent', 'LD-NEW-1'], [$meera['engage_stage'], $meera['engage_lead_code']]);

        // Idempotent: resending the whole batch changes nothing.
        $again = self::data($this->send($events))['data'];
        $this->assertSame([0, 5, 1], [$again['accepted'], $again['duplicate'], $again['rejected']]);
        $this->assertSame(5, (int) $db->query('SELECT COUNT(*) AS n FROM reach_lead_outcomes')->getRowArray()['n']);
    }

    public function testLeadStageOnlyMovesForward(): void
    {
        $this->send([self::event('s-2', ['reach_lead_id' => $this->codeLead, 'stage' => 'converted', 'sales_status' => 'won', 'occurred_at' => '2026-10-16T12:00:00Z'])]);
        $this->send([self::event('s-1', ['reach_lead_id' => $this->codeLead, 'stage' => 'demo', 'sales_status' => 'open', 'occurred_at' => '2026-10-16T09:00:00Z'])]);
        $lead = Database::connect()->table('reach_leads')->where('id', $this->codeLead)->get()->getRowArray();
        $this->assertSame(['converted', 'won'], [$lead['engage_stage'], $lead['engage_sales_status']], 'a late, older event does not regress the lead');
        $this->assertSame('LD-000200', $lead['engage_lead_code'], 'an existing code is kept');
    }

    public function testSummaryForPeriod(): void
    {
        $this->send([
            self::event('p-1', ['reach_lead_id' => $this->landingLead, 'stage' => 'converted', 'value_inr' => '20000.00', 'occurred_at' => '2026-10-16T10:00:00+05:30']),
            self::event('p-2', ['reach_lead_id' => $this->landingLead, 'stage' => 'converted', 'value_inr' => '20000.00', 'occurred_at' => '2026-10-16T11:00:00+05:30']),
            self::event('p-3', ['reach_lead_id' => $this->landingLead, 'stage' => 'payment_confirmed', 'value_inr' => '11800.00', 'occurred_at' => '2026-10-16T12:00:00+05:30']),
            self::event('p-4', ['reach_lead_id' => $this->touchLead, 'stage' => 'converted', 'sales_status' => 'payment_confirmed', 'value_inr' => '5000.50', 'occurred_at' => '2026-10-16T23:59:00+05:30']),
            self::event('p-5', ['lead_code' => 'LD-SELL', 'seller_code' => 'SLR-000123', 'stage' => 'qualified', 'occurred_at' => '2026-10-16T08:00:00+05:30']),
            // Outside the IST day 2026-10-16 (00:10 IST on the 17th).
            self::event('p-6', ['reach_lead_id' => $this->codeLead, 'stage' => 'converted', 'value_inr' => '999.00', 'occurred_at' => '2026-10-16T18:40:00Z']),
        ]);

        $s = LeadOutcomeSummary::forPeriod('2026-10-16', '2026-10-16');
        $this->assertSame(5, $s['events']);
        $this->assertSame([4, 1], [$s['matched'], $s['unmatched']]);
        $this->assertSame(2, $s['conversions'], 'a lead sent `converted` twice is one conversion');
        $this->assertSame('16800.50', $s['revenue_inr'], 'payment_confirmed stage + payment_confirmed status');
        $this->assertSame('45000.50', $s['converted_value_inr']);
        $this->assertSame(1, $s['by_stage']['payment_confirmed']);
        $this->assertSame(1, $s['by_stage']['qualified']);
        $this->assertSame(0, $s['by_stage']['lost']);

        $campaigns = array_column($s['by_campaign'], null, 'campaign_id');
        $this->assertSame('Diwali GST push', $campaigns[$this->campaignId]['campaign_name']);
        $this->assertSame(['11800.00', 1], [$campaigns[$this->campaignId]['revenue_inr'], $campaigns[$this->campaignId]['conversions']]);
        $this->assertSame('5000.50', $campaigns[$this->otherCampaignId]['revenue_inr']);
        $sources = array_column($s['by_source'], null, 'source');
        $this->assertSame('11800.00', $sources['google']['revenue_inr']);
        $this->assertSame('5000.50', $sources['linkedin']['revenue_inr']);
        $this->assertSame(['SLR-000123', 1], [$s['by_seller'][0]['seller_code'], $s['by_seller'][0]['events']]);
        $this->assertSame($this->contentId, $s['by_content'][0]['content_identity_id']);

        $empty = LeadOutcomeSummary::forPeriod('2026-01-01', '2026-01-31');
        $this->assertSame([0, 0, '0.00', '0.00'], [$empty['events'], $empty['conversions'], $empty['revenue_inr'], $empty['converted_value_inr']]);
    }

    public function testStaffApis(): void
    {
        $this->send([
            self::event('a-1', ['reach_lead_id' => $this->landingLead, 'stage' => 'converted', 'value_inr' => '100.00', 'occurred_at' => gmdate('c', time() - 3600)]),
            self::event('a-2', ['lead_code' => 'LD-ORPHAN', 'stage' => 'lost', 'occurred_at' => gmdate('c', time() - 7200)]),
        ]);
        $h = $this->authAs('super_admin');

        $list = $this->withHeaders($h)->call('GET', 'v1/leads/outcomes?stage=converted');
        $this->assertSame(200, $list->response()->getStatusCode());
        $l = self::data($list)['data'];
        $this->assertSame(1, $l['total']);
        $this->assertSame(['a-1', 'Asha Rao', 'Diwali GST push', '100.00'],
            [$l['items'][0]['event_id'], $l['items'][0]['lead_name'], $l['items'][0]['campaign_name'], $l['items'][0]['value_inr']]);
        $this->assertSame(1, self::data($this->withHeaders($h)->call('GET', 'v1/leads/outcomes?match=unmatched'))['data']['total']);
        $this->assertSame(1, self::data($this->withHeaders($h)->call('GET', 'v1/leads/outcomes?search=ORPHAN'))['data']['total']);

        foreach (['v1/leads/outcomes/summary', 'v1/analytics/outcomes'] as $url) {
            $sum = $this->withHeaders($h)->call('GET', $url);
            $this->assertSame(200, $sum->response()->getStatusCode(), $url);
            $sd = self::data($sum)['data'];
            $this->assertSame([2, 1, true], [$sd['events'], $sd['conversions'], $sd['inbound_configured']], $url);
            $this->assertNotNull($sd['last_received_at']);
        }
        $this->assertSame(422, $this->withHeaders($h)->call('GET', 'v1/analytics/outcomes?from=bogus')->response()->getStatusCode());

        // analyst holds analytics.view but not lead.view.
        $analyst = $this->authAs('analyst');
        $this->assertSame(200, $this->withHeaders($analyst)->call('GET', 'v1/analytics/outcomes')->response()->getStatusCode());
        $this->assertSame(403, $this->withHeaders($analyst)->call('GET', 'v1/leads/outcomes')->response()->getStatusCode());
    }

    public function testOutcomeListDrillFilters(): void
    {
        $this->send([
            self::event('d-1', ['reach_lead_id' => $this->landingLead, 'stage' => 'converted', 'value_inr' => '100.00', 'occurred_at' => gmdate('c', time() - 3600)]),
            self::event('d-2', ['lead_code' => 'LD-SELL-2', 'seller_code' => 'SLR-000999', 'stage' => 'qualified', 'occurred_at' => gmdate('c', time() - 7200)]),
            self::event('d-3', ['reach_lead_id' => $this->touchLead, 'stage' => 'demo', 'occurred_at' => gmdate('c', time() - 5400)]),
            self::event('d-4', ['reach_lead_id' => $this->landingLead, 'stage' => 'converted', 'sales_status' => 'paid', 'value_inr' => '50.00', 'occurred_at' => gmdate('c', time() - 1800)]),
        ]);
        $h     = $this->authAs('super_admin');
        $total = fn (string $qs) => self::data($this->withHeaders($h)->call('GET', 'v1/leads/outcomes?' . $qs))['data']['total'];
        $sum   = self::data($this->withHeaders($h)->call('GET', 'v1/leads/outcomes/summary'))['data'];

        // Each breakdown row of the summary opens exactly the events it counted.
        $byCampaign = array_column($sum['by_campaign'], 'events', 'campaign_id');
        $this->assertSame($byCampaign[$this->campaignId], $total('campaign_id=' . $this->campaignId));
        foreach ($sum['by_source'] as $r) {
            $this->assertSame($r['events'], $total('source=' . urlencode($r['source'])), $r['source']);
        }
        $this->assertSame(1, $total('source=seller'));
        $this->assertSame(0, $total('source=unattributed'));
        $this->assertSame(1, $total('seller_code=SLR-000999'));
        $this->assertSame($sum['by_content'][0]['events'], $total('content_identity_id=' . $this->contentId));
        $this->assertSame([$sum['matched'], $sum['unmatched']], [$total('match=matched'), $total('match=unmatched')]);
        $this->assertSame(1, $total('match=reach_lead_id&stage=demo'));
        $this->assertSame(1, $total('paid=1'), 'paid sales_status counts as revenue, as in the summary');
    }

    public function testLeadListDrillFilters(): void
    {
        $db = Database::connect();
        $db->table('reach_leads')->insert(['name' => 'Old Lead', 'email' => 'old@example.test', 'priority' => 'normal', 'created_at' => date('Y-m-d H:i:s', strtotime('-60 days'))]);
        $h     = $this->authAs('super_admin');
        $items = fn (string $qs) => self::data($this->withHeaders($h)->call('GET', 'v1/leads?' . $qs))['data'];

        $this->assertSame([$this->landingLead], array_map('intval', array_column($items('campaign_id=' . $this->campaignId)['items'], 'id')));
        $this->assertSame([$this->codeLead], array_map('intval', array_column($items('lead_id=' . $this->codeLead)['items'], 'id')));
        $this->assertSame(4, $items('days=30')['total'], 'the 60-day-old lead is outside "last 30 days"');
        $this->assertSame(5, $items('days=bogus')['total'], 'a malformed window is ignored');
        $this->assertSame(1, $items('search=LD-000200')['total'], 'search covers the Engage lead code');
        $this->assertSame(1, $items('search=SLR-000123')['total'], 'and the seller referral code');

        // The campaign page's "Leads captured" count is the list it links to.
        $campaign = self::data($this->withHeaders($h)->call('GET', 'v1/campaigns/' . $this->campaignId))['data'];
        $this->assertSame($items('campaign_id=' . $this->campaignId)['total'], $campaign['leads_captured']);
        $this->assertSame(0, $campaign['outcome_events']);
        $campaigns = fn (string $qs) => self::data($this->withHeaders($h)->call('GET', 'v1/campaigns?' . $qs))['data']['total'];
        $this->assertSame([2, 1], [$campaigns('days=30'), $campaigns('campaign_type=landing')]);
    }

    public function testPublicCaptureStoresRefAndUtm(): void
    {
        $res = $this->withEnv('ENGAGE_API_BASE_URL', null, fn () => $this->withHeaders(['X-Public-Capture-Token' => 'test-public-capture-token'])
            ->withBodyFormat('json')
            ->call('POST', 'v1/public/leads/capture', [
                'name' => 'Seller Lead', 'email' => 'Seller.Lead@Example.test', 'source_kind' => 'landing',
                'ref' => 'slr-000777', 'utm_source' => 'whatsapp', 'utm_campaign' => 'oct-promo',
                'landing_url' => 'https://www.aicountly.com/?ref=SLR-000777', 'referrer' => 'https://wa.me/',
            ]));
        $this->assertSame(201, $res->response()->getStatusCode());
        $id = self::data($res)['data']['lead_id'];
        $row = Database::connect()->table('reach_leads')->where('id', $id)->get()->getRowArray();
        $this->assertSame(['SLR-000777', 'whatsapp', 'oct-promo', 'https://www.aicountly.com/?ref=SLR-000777', 'https://wa.me/'],
            [$row['ref_code'], $row['utm_source'], $row['utm_campaign'], $row['landing_url'], $row['referrer_url']]);
    }
}

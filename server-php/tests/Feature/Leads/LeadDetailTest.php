<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * GET v1/leads/:id returns the lead plus everything the lead detail page shows:
 * campaign, landing page, Engage push attempts, outcomes, attribution, social
 * inbox origin and the audit trail — and only this lead's rows.
 *
 * @internal
 */
final class LeadDetailTest extends ApiTestCase
{
    private static function data($res): array
    {
        return json_decode((string) $res->getJSON(), true)['data'];
    }

    public function testShowReturnsTheLeadsRelatedRecords(): void
    {
        $db = Database::connect();
        $h  = $this->authAs('reach_admin');

        $db->table('reach_campaigns')->insert(['name' => 'Diwali GST push', 'campaign_type' => 'landing', 'status' => 'approved', 'tenant_id' => 0]);
        $campaignId = (int) $db->insertID();
        $db->table('reach_landing_pages')->insert(['campaign_id' => $campaignId, 'slug' => 'gst', 'title' => 'GST filing', 'body' => '']);
        $landingId = (int) $db->insertID();

        // Created through the API so the audit trail has a real lead.create entry.
        $res = $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/leads', [
            'name' => 'Asha Rao', 'email' => 'Asha@Example.test', 'campaign_id' => $campaignId,
            'landing_page_id' => $landingId, 'source_kind' => 'social_inbox', 'utm_source' => 'google', 'ref' => 'slr-1',
        ]);
        $this->assertSame(201, $res->response()->getStatusCode());
        $leadId = (int) self::data($res)['id'];
        $db->table('reach_leads')->insert(['name' => 'Other lead', 'priority' => 'normal']);
        $otherId = (int) $db->insertID();

        foreach ([[$leadId, 1, false], [$leadId, 2, true], [$otherId, 1, true]] as [$lead, $n, $ok]) {
            $db->query(
                'INSERT INTO reach_engage_push_attempts (lead_id, attempt_number, ok, response_status, error_message, response_body, attempted_at)
                 VALUES (?, ?, ?, ?, ?, ?::jsonb, NOW() + (? || \' minutes\')::interval)',
                [$lead, $n, $ok, $ok ? 201 : 502, $ok ? null : 'bad gateway', json_encode(['ok' => $ok]), (string) $n],
            );
        }
        $db->query(
            "INSERT INTO reach_lead_outcomes (event_id, lead_id, match_method, stage, value_inr, occurred_at, campaign_id, source, attribution_method)
             VALUES ('ev-1', ?, 'reach_lead_id', 'converted', 1500, NOW(), ?, 'google', 'lead_campaign'),
                    ('ev-2', ?, 'reach_lead_id', 'qualified', NULL, NOW(), NULL, NULL, 'unattributed')",
            [$leadId, $campaignId, $otherId],
        );
        $db->query("INSERT INTO reach_attribution_touchpoints (tenant_id, visitor_pseudonym_hash, channel, utm_source, touched_at) VALUES
            (1, 'v1', 'social', 'linkedin', NOW() - INTERVAL '3 days'),
            (1, 'v1', 'email', NULL, NOW() - INTERVAL '2 days'),
            (1, 'v1', 'search', 'google', NOW() - INTERVAL '1 day'),
            (1, 'v2', 'paid', NULL, NOW())");
        $tp = array_column($db->query('SELECT id, channel FROM reach_attribution_touchpoints')->getResultArray(), 'id', 'channel');
        $db->query(
            "INSERT INTO reach_attribution_conversion_links (tenant_id, lead_id, first_touchpoint_id, last_touchpoint_id, converted_at)
             VALUES (1, ?, ?, ?, NOW()), (1, ?, ?, ?, NOW())",
            [$leadId, $tp['social'], $tp['search'], $otherId, $tp['paid'], $tp['paid']],
        );
        $db->query(
            "INSERT INTO reach_social_inbox (network, external_id, author_handle, text, status, lead_id)
             VALUES ('instagram', 'c-1', '@asha', 'Price for GST filing?', 'lead_created', ?)",
            [$leadId],
        );

        $res = $this->withHeaders($h)->call('GET', 'v1/leads/' . $leadId);
        $this->assertSame(200, $res->response()->getStatusCode());
        $d = self::data($res);
        $this->assertSame(['Asha Rao', 'asha@example.test', 'SLR-1', 'google'], [$d['name'], $d['email'], $d['ref_code'], $d['utm_source']]);
        $r = $d['related'];
        $this->assertSame('Diwali GST push', $r['campaign']['name']);
        $this->assertSame('GST filing', $r['landing_page']['title']);

        $this->assertSame(['2', '1'], array_column($r['push_attempts'], 'attempt_number'), 'newest attempt first, only this lead');
        $this->assertSame([true, false], array_column($r['push_attempts'], 'ok'));
        $this->assertSame(['ok' => true], $r['push_attempts'][0]['response_body']);

        $this->assertSame(['ev-1'], array_column($r['outcomes'], 'event_id'));
        $this->assertSame('Diwali GST push', $r['outcomes'][0]['campaign_name']);

        $this->assertCount(1, $r['attribution']['conversions']);
        $this->assertSame(['social', 'search'], [$r['attribution']['conversions'][0]['first_touch_channel'], $r['attribution']['conversions'][0]['last_touch_channel']]);
        // The visitor's whole journey (first, middle, last), not the other lead's touchpoint.
        $this->assertSame(['social', 'email', 'search'], array_column($r['attribution']['touchpoints'], 'channel'));

        $this->assertSame(['@asha'], array_column($r['social_inbox'], 'author_handle'));
        $this->assertSame(['lead.create'], array_column($r['audit'], 'action'));
        $this->assertSame('Asha Rao', $r['audit'][0]['new_value']['name']);

        $this->assertSame(404, $this->withHeaders($h)->call('GET', 'v1/leads/999999')->response()->getStatusCode());
    }

    public function testEmptySectionsForABareLead(): void
    {
        $db = Database::connect();
        $db->table('reach_leads')->insert(['name' => 'Bare', 'priority' => 'normal']);
        $id = (int) $db->insertID();

        $r = self::data($this->withHeaders($this->authAs('reach_admin'))->call('GET', 'v1/leads/' . $id))['related'];
        $this->assertNull($r['campaign']);
        $this->assertNull($r['landing_page']);
        $this->assertSame([[], [], [], [], []], [$r['push_attempts'], $r['outcomes'], $r['social_inbox'], $r['audit'], $r['attribution']['conversions']]);
    }
}

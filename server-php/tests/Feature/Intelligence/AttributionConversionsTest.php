<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The Attribution overview's tiles and first-touch bars count exactly the rows
 * GET intelligence/attribution/conversions lists for the matching filter.
 *
 * @internal
 */
final class AttributionConversionsTest extends ApiTestCase
{
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h = $this->authAs('reach_admin');
        $db = Database::connect();
        $db->table('reach_leads')->insert(['name' => 'Asha Rao', 'priority' => 'normal']);
        $lead = (int) $db->insertID();

        $db->query("INSERT INTO reach_attribution_touchpoints (tenant_id, channel, utm_source) VALUES
            (1, 'social', NULL), (1, '  ', 'google'), (1, NULL, NULL), (1, 'email', NULL), (2, 'social', NULL)");
        $tp = array_map('intval', array_column($db->query('SELECT id FROM reach_attribution_touchpoints ORDER BY id')->getResultArray(), 'id'));
        [$social, $google, $unknown, $email, $otherTenant] = $tp;

        // [first, last, matching_method, confidence_state, converted_at, tenant]
        $rows = [
            [$social, $social, 'first_touch', 'inferred', '2026-10-01 10:00:00+00', 1],   // attributed · social
            [$social, $email, 'last_touch', 'confirmed', '2026-10-02 10:00:00+00', 1],    // attributed · social
            [$google, null, 'last_touch', 'inferred', '2026-10-03 10:00:00+00', 1],        // attributed · google (utm fallback)
            [$unknown, $unknown, 'manual', 'corrected', '2026-10-04 10:00:00+00', 1],      // attributed · Unknown
            [null, $email, 'last_touch', 'inferred', '2026-10-05 10:00:00+00', 1],         // attributed, no first touch → no bar
            [$email, $email, 'unattributed', 'inferred', '2026-10-06 10:00:00+00', 1],     // unattributed (method)
            [$email, null, 'last_touch', 'unattributed', '2026-10-07 10:00:00+00', 1],     // unattributed (confidence)
            [null, null, 'last_touch', 'inferred', '2026-10-08 10:00:00+00', 1],           // unattributed (no touchpoint)
            [$otherTenant, $otherTenant, 'first_touch', 'inferred', '2026-10-08 10:00:00+00', 2],
        ];
        foreach ($rows as [$f, $l, $m, $c, $at, $t]) {
            $db->query(
                'INSERT INTO reach_attribution_conversion_links (tenant_id, lead_id, first_touchpoint_id, last_touchpoint_id, matching_method, confidence_state, converted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$t, $lead, $f, $l, $m, $c, $at],
            );
        }
    }

    private function fetch(string $url): array
    {
        $res = $this->withHeaders($this->h)->call('GET', $url);
        $this->assertSame(200, $res->response()->getStatusCode(), $url);

        return json_decode((string) $res->getJSON(), true)['data'];
    }

    private function total(string $qs): int
    {
        return $this->fetch('v1/intelligence/attribution/conversions?' . $qs)['total'];
    }

    public function testTilesAndBarsEqualTheirListTotals(): void
    {
        $o = $this->fetch('v1/intelligence/attribution');
        $s = $o['stats'];
        $this->assertSame([8, 5, 3, 62.5], [$s['total_conversions'], $s['attributed'], $s['unattributed'], $s['attribution_rate']]);

        $this->assertSame($s['total_conversions'], $this->total(''));
        $this->assertSame($s['attributed'], $this->total('attributed=1'));
        $this->assertSame($s['unattributed'], $this->total('attributed=0'));

        $bars = array_column($o['first_touch_breakdown'], 'conversions', 'channel');
        ksort($bars);
        $this->assertSame(['Unknown' => 1, 'google' => 1, 'social' => 2], $bars);
        $this->assertSame('social', $o['first_touch_breakdown'][0]['channel'], 'largest bar first');
        foreach ($bars as $channel => $n) {
            $this->assertSame($n, $this->total('attributed=1&channel=' . rawurlencode($channel)), "bar {$channel}");
        }

        // Narrowed overviews keep the same identity.
        foreach (['model=last_touch', 'from=2026-10-02&to=2026-10-06', 'confidence=inferred'] as $qs) {
            $n = $this->fetch('v1/intelligence/attribution?' . $qs)['stats'];
            $this->assertSame($n['total_conversions'], $this->total($qs), $qs);
            $this->assertSame($n['attributed'], $this->total($qs . '&attributed=1'), $qs);
            $this->assertSame($n['unattributed'], $this->total($qs . '&attributed=0'), $qs);
        }
    }

    public function testListFiltersAndRows(): void
    {
        $this->assertSame(5, $this->total('model=last_touch'));
        $this->assertSame(2, $this->total('from=2026-10-02&to=2026-10-03'), 'to is inclusive');
        $this->assertSame(1, $this->total('tenant_id=2'));
        $this->assertSame(8, $this->total('model=bogus'), 'unknown values are ignored');

        $page = $this->fetch('v1/intelligence/attribution/conversions?attributed=1&channel=social&limit=1');
        $this->assertSame([2, 1, 1], [$page['total'], count($page['items']), $page['limit']]);
        $row = $page['items'][0];
        $this->assertSame(['social', 'email', true, 'Asha Rao'], [$row['first_touch_channel'], $row['last_touch_channel'], $row['attributed'], $row['lead_name']]);

        $unattributed = $this->fetch('v1/intelligence/attribution/conversions?attributed=0')['items'];
        $this->assertSame([false, false, false], array_column($unattributed, 'attributed'));

        $res = $this->withHeaders($this->h)->call('GET', 'v1/intelligence/attribution/conversions?from=2026-13-01');
        $this->assertSame(422, $res->response()->getStatusCode());
    }
}

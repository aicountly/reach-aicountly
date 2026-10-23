<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * GET v1/social/inbox (+ summary) and POST v1/social/inbox/{id}/retry:
 * permission-gated, filters, Flow ticket links, honest "only failed items".
 *
 * @covers \App\Controllers\Api\V1\SocialInboxController
 *
 * @internal
 */
final class SocialInboxApiTest extends ApiTestCase
{
    private function item(array $row): int
    {
        $db = Database::connect();
        $db->table('reach_social_inbox')->insert($row + [
            'network' => 'linkedin', 'account_id' => 'acc_li', 'kind' => 'comment', 'text' => 'hello', 'author_handle' => 'asha',
            'received_at' => gmdate('Y-m-d H:i:s') . '+00', 'status' => 'new',
        ]);
        return (int) $db->insertID();
    }

    public function testListFiltersAndTicketLinks(): void
    {
        $this->item(['external_id' => 'c1', 'status' => 'ticket_created', 'classification' => 'complaint', 'ticket_number' => 'TCK-2026-00042', 'ticket_id' => 42, 'severity' => 'high', 'vetting_reasons_json' => '["ok"]']);
        $this->item(['external_id' => 'c2', 'status' => 'replied', 'classification' => 'question', 'reply_status' => 'sent', 'reply_text' => 'Hi — Aicountly team']);
        $h = $this->authAs('super_admin');

        $all = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/social/inbox')->getJSON(), true)['data'];
        $this->assertSame(2, $all['total']);
        $complaints = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/social/inbox?classification=complaint')->getJSON(), true)['data'];
        $this->assertSame(1, $complaints['total']);
        $item = $complaints['items'][0];
        $this->assertSame('https://flow.aicountly.org/desk/tickets/42', $item['ticket_url']);
        $this->assertSame(['ok'], $item['vetting_reasons']);
        $this->assertArrayNotHasKey('raw_json', $item);
        $this->assertSame(1, json_decode((string) $this->withHeaders($h)->call('GET', 'v1/social/inbox?search=TCK-2026')->getJSON(), true)['data']['total']);

        $sum = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/social/inbox/summary')->getJSON(), true)['data'];
        $this->assertSame(2, $sum['last_24h']['received']);
        $this->assertTrue($sum['config']['enabled']);
        $this->assertStringContainsString('key is not set', $sum['config']['flow']);
    }

    /** Each "Last 24 hours" tile opens the rows it counted. */
    public function testTileFiltersMatchTheSummaryCounts(): void
    {
        $db = Database::connect();
        $db->table('reach_leads')->insert(['name' => 'Inbox lead', 'source_kind' => 'social_inbox', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $leadId = (int) $db->insertID();
        $now = gmdate('Y-m-d H:i:s') . '+00';
        $old = gmdate('Y-m-d H:i:s', time() - 3 * 86400) . '+00';

        $this->item(['external_id' => 't1', 'status' => 'ticket_created', 'ticket_number' => 'TCK-1']);
        $this->item(['external_id' => 'l1', 'status' => 'lead_created', 'lead_id' => $leadId]);
        $this->item(['external_id' => 'r1', 'status' => 'replied', 'reply_status' => 'sent', 'replied_at' => $now]);
        // Read three days ago, replied just now: counts as a reply today, not as read today.
        $this->item(['external_id' => 'r2', 'status' => 'replied', 'reply_status' => 'sent', 'replied_at' => $now, 'fetched_at' => $old]);
        $this->item(['external_id' => 'o1', 'status' => 'ticket_created', 'ticket_number' => 'TCK-0', 'fetched_at' => $old]);
        $h = $this->authAs('super_admin');

        $day = json_decode((string) $this->withHeaders($h)->call('GET', 'v1/social/inbox/summary')->getJSON(), true)['data']['last_24h'];
        $total = fn (string $qs): int => json_decode((string) $this->withHeaders($h)->call('GET', 'v1/social/inbox?' . $qs)->getJSON(), true)['data']['total'];

        $this->assertSame(3, $day['received']);
        $this->assertSame($day['received'], $total('since_hours=24'));
        $this->assertSame(2, $day['replies_sent']);
        $this->assertSame($day['replies_sent'], $total('reply_status=sent&replied_since_hours=24'));
        $this->assertSame(1, $day['tickets']);
        $this->assertSame($day['tickets'], $total('has=ticket&since_hours=24'));
        $this->assertSame(1, $day['leads']);
        $this->assertSame($day['leads'], $total('has=lead&since_hours=24'));
        // Unknown / out-of-range values are ignored, not an error.
        $this->assertSame(5, $total('has=everything&since_hours=abc'));
    }

    public function testRetryIsOnlyForFailedItemsAndNeedsDispatch(): void
    {
        $ok = $this->item(['external_id' => 'c3', 'status' => 'replied', 'reply_status' => 'sent']);
        $h  = $this->authAs('super_admin');
        $this->assertSame(409, $this->withHeaders($h)->call('POST', "v1/social/inbox/{$ok}/retry")->response()->getStatusCode());
        $this->assertSame(404, $this->withHeaders($h)->call('POST', 'v1/social/inbox/999999/retry')->response()->getStatusCode());

        $this->assertSame(401, $this->withHeaders([])->call('GET', 'v1/social/inbox')->response()->getStatusCode());
        $analyst = $this->authAs('analyst');
        $this->assertSame(403, $this->withHeaders($analyst)->call('POST', "v1/social/inbox/{$ok}/retry")->response()->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use App\Models\Distribution\ChannelSuppressionModel;
use Tests\Support\ApiTestCase;

/**
 * GET v1/distribution/suppressions?channel=&reason= — the SMS overview and
 * the analytics bounce / unsubscribe counts link here with these filters.
 *
 * @internal
 */
final class SuppressionListFilterTest extends ApiTestCase
{
    private function suppress(string $channel, string $address, string $reason): void
    {
        \Config\Database::connect()->table('reach_channel_suppressions')->insert([
            'tenant_id' => 0, 'channel' => $channel,
            'address_hash' => ChannelSuppressionModel::hashAddress(0, $channel, $address),
            'address_masked' => ChannelSuppressionModel::maskAddress($address), 'reason' => $reason, 'source' => 'test',
        ]);
    }

    private function list(string $query = ''): array
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->get('v1/distribution/suppressions' . $query);
        $res->assertStatus(200);

        return json_decode($res->getJSON(), true)['data'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->suppress('email', 'a@example.com', 'bounce');
        $this->suppress('email', 'b@example.com', 'unsubscribe');
        $this->suppress('sms', '+919876543210', 'opt_out');
    }

    public function testChannelNarrowsListAndTotal(): void
    {
        $data = $this->list('?channel=sms');
        $this->assertSame(1, $data['total']);
        $this->assertSame(['sms'], array_values(array_unique(array_column($data['data'], 'channel'))));
    }

    public function testReasonAndChannelCombine(): void
    {
        $data = $this->list('?channel=email&reason=bounce');
        $this->assertSame(1, $data['total']);
        $this->assertSame('bounce', $data['data'][0]['reason']);
    }

    public function testUnknownValuesAreIgnored(): void
    {
        $this->assertSame(3, $this->list('?channel=fax&reason=whatever')['total']);
        $this->assertSame(3, $this->list()['total']);
    }
}

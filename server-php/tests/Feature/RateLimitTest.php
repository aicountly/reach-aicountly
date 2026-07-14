<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

/**
 * B2 test #6 — rate limiting on bot dispatch.
 *
 * Uses the small bot_dispatch route limit (30/min/user). Fires above the cap
 * and asserts the 31st call returns 429 with a Retry-After header.
 */
final class RateLimitTest extends ApiTestCase
{
    public function testBotDispatchAllowsWithinLimitAndBlocksAfter(): void
    {
        $headers = $this->authAs('super_admin');
        $limit   = 30;

        // Below limit — should succeed.
        for ($i = 0; $i < $limit; $i++) {
            $r = $this->withHeaders($headers)->call('POST', 'v1/bot/dispatch', [
                'action'  => 'generate_campaign_ideas',
                'payload' => ['topic' => 'unit test ' . $i],
            ]);
            $this->assertSame(202, $r->response()->getStatusCode(), "Iteration {$i} should be allowed under rate limit");
        }

        // Over limit — should be blocked.
        $blocked = $this->withHeaders($headers)->call('POST', 'v1/bot/dispatch', [
            'action'  => 'generate_campaign_ideas',
            'payload' => ['topic' => 'over limit'],
        ]);
        $this->assertSame(429, $blocked->response()->getStatusCode());
        $body = json_decode((string) $blocked->getJSON(), true);
        $this->assertFalse($body['ok']);
        $this->assertArrayHasKey('retry_after', $body);
        $this->assertNotEmpty($blocked->getHeaderLine('Retry-After'));
    }
}


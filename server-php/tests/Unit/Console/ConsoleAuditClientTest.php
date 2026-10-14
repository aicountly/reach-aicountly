<?php

namespace Tests\Unit\Console;

use App\Libraries\ConsoleAuditClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Central audit to Console: the event shape and the request (no database). Reach used to post to
 * {CONSOLE_API_BASE_URL}/audit, a route Console never had (aicountly-smoke-app e2e).
 *
 * @internal
 */
final class ConsoleAuditClientTest extends CIUnitTestCase
{
    public function testMapsReachPayloadToConsoleEvent(): void
    {
        $e = ConsoleAuditClient::toEvent('reach.blog.published', [
            'user_id' => 4, 'actor_type' => 'human', 'entity_type' => 'publishing', 'entity_id' => 12,
            'new_value' => ['status' => 'published'], 'metadata' => ['slug' => 'gst'], 'reason' => null,
            'ip' => '10.0.0.2', 'user_agent' => 'UA',
        ], 'reach:audit_logs:9', ['email' => 'mm@aicountly.com', 'name' => 'Meera']);

        $this->assertSame(['blog.published', 'reach', 'reach:audit_logs:9'], [$e['action'], $e['app_code'], $e['external_id']]);
        $this->assertSame(['publishing', 12, 'mm@aicountly.com', 'Meera'], [$e['entity_type'], $e['entity_id'], $e['actor_email'], $e['actor_name']]);
        $this->assertSame(['status' => 'published'], $e['new_value']);
        $this->assertSame(['10.0.0.2', 'UA'], [$e['ip'], $e['user_agent']]);
        $this->assertSame(['type' => 'reach.blog.published', 'source' => 'reach.aicountly.org', 'user_id' => 4, 'actor_type' => 'human', 'metadata' => ['slug' => 'gst']], $e['metadata']);
        $this->assertNotFalse(strtotime($e['occurred_at']));
    }

    public function testPostsToConsolePortalAuditWithReachsOwnKey(): void
    {
        $calls = [];
        $client = new ConsoleAuditClient(static function (string $url, array $headers, string $body) use (&$calls): array {
            $calls[] = [$url, $headers, json_decode($body, true)];

            return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ['results' => [['index' => 0, 'status' => 'stored', 'id' => 8]]]])];
        }, 'https://console.test/api/', 'cnnk_reach');

        $event = ConsoleAuditClient::toEvent('reach.auth.logout', [], 'reach:audit_logs:1');
        $res = $client->send([$event]);

        $this->assertSame('https://console.test/api/portal/audit', $calls[0][0]);
        $this->assertContains('Authorization: Bearer cnnk_reach', $calls[0][1]);
        $this->assertSame(['events' => [$event]], $calls[0][2]);
        $this->assertSame([200, 'stored'], [$res['status'], $res['results'][0]['status']]);
    }

    public function testAFailedAnswerHasNoResults(): void
    {
        $c = new ConsoleAuditClient(static fn (): array => ['status' => 404, 'body' => '{"success":false,"message":"Route not found."}'], 'https://c.test/api', 'k');
        $res = $c->send([['action' => 'x']]);
        $this->assertNull($res['results']);
        $this->assertStringContainsString('Route not found', $res['error']);

        $down = new ConsoleAuditClient(static fn (): array => ['status' => 0, 'body' => false, 'error' => 'Connection refused'], 'https://c.test/api', 'k');
        $this->assertSame('Connection refused', $down->send([['action' => 'x']])['error']);
    }

    public function testConfiguredOnlyWithUrlAndToken(): void
    {
        $this->assertFalse((new ConsoleAuditClient(null, '', 'k'))->isConfigured());
        $this->assertFalse((new ConsoleAuditClient(null, 'https://c.test/api', ''))->isConfigured());
        $this->assertTrue((new ConsoleAuditClient(null, 'https://c.test/api', 'k'))->isConfigured());
    }
}

<?php

namespace Tests\Feature\Console;

use App\Libraries\ConsoleAuditClient;
use Config\Database;
use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * The central-audit outbox (reach_console_sync_logs, migration 2026-10-14-100002): queued first,
 * delivered at once when Console takes it (event cleared), kept pending with back-off when it does
 * not, retried in one batch by reach:console-audit-deliver, rejected kept and not retried, dead after
 * 12 attempts; AuditLogger fans out every auth.* action once, as reach:audit_logs:<id>, with the
 * Reach user as the actor.
 *
 * @internal
 */
final class ConsoleAuditOutboxTest extends DatabaseTestCase
{
    /** @var list<array<string,mixed>> */
    private array $sent = [];

    /** @var array{status:int, body:?string}|callable */
    private $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        $this->next = $this->consoleAnswers('stored');
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    private function client(): ConsoleAuditClient
    {
        return new ConsoleAuditClient(function (string $url, array $headers, string $body): array {
            $req = json_decode($body, true);
            $this->sent[] = $req;

            return is_callable($this->next) ? ($this->next)($req) : $this->next;
        }, 'https://console.test/api', 'cnnk_reach', Database::connect());
    }

    private function consoleAnswers(string $status): callable
    {
        return static function (array $req) use ($status): array {
            $results = [];
            foreach ($req['events'] as $i => $e) {
                $results[] = ['index' => $i, 'external_id' => $e['external_id'], 'status' => $status, 'id' => 700 + $i];
            }

            return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ['results' => $results]])];
        };
    }

    /** @return array<string,mixed>|null */
    private function row(string $ext): ?array
    {
        return Database::connect()->query('SELECT * FROM reach_console_sync_logs WHERE external_id = ?', [$ext])->getRowArray();
    }

    public function testDeliveredAtOnceAndQueuedOnce(): void
    {
        $this->assertSame('delivered', $this->client()->event('reach.blog.published', ['entity_type' => 'publishing', 'entity_id' => 3], 'reach:audit_logs:1'));
        $row = $this->row('reach:audit_logs:1');
        $this->assertSame(['delivered', 't', null, 700], [$row['status'], $row['ok'] === true || $row['ok'] === 't' ? 't' : 'f', $row['event'], (int) $row['console_audit_id']]);

        $this->assertSame('duplicate', $this->client()->event('reach.blog.published', [], 'reach:audit_logs:1'));
        $this->assertCount(1, $this->sent);
    }

    public function testFailuresAreRetriedRejectedOrDead(): void
    {
        $this->next = ['status' => 404, 'body' => '{"success":false,"message":"Route not found."}'];
        $this->assertSame('pending', $this->client()->event('reach.auth.logout', ['entity_type' => 'user', 'entity_id' => 1], 'reach:audit_logs:2'));
        $this->next = ['status' => 0, 'body' => null];
        $this->client()->event('reach.lead.pushed', [], 'reach:audit_logs:3');

        $r2 = $this->row('reach:audit_logs:2');
        $this->assertSame(['pending', 1, 404], [$r2['status'], (int) $r2['attempts'], (int) $r2['response_status']]);
        $this->assertStringContainsString('Route not found', (string) $r2['error_message']);
        $this->assertGreaterThan(time() + 60, strtotime((string) $r2['next_attempt_at']));

        Database::connect()->query("UPDATE reach_console_sync_logs SET next_attempt_at = NOW() - INTERVAL '1 second' WHERE status = 'pending'");
        $this->sent = [];
        $this->next = $this->consoleAnswers('duplicate');
        $res = $this->client()->deliverDue();
        $this->assertCount(1, $this->sent);
        $this->assertSame(['auth.logout', 'lead.pushed'], array_column($this->sent[0]['events'], 'action'));
        $this->assertSame(2, $res['delivered']);
        $this->assertSame('delivered', $this->row('reach:audit_logs:3')['status']);

        $this->next = static fn (array $req): array => ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ['results' => [
            ['index' => 0, 'status' => 'rejected', 'error' => 'action_required'],
        ]]])];
        $this->assertSame('rejected', $this->client()->event('reach.x', [], 'reach:audit_logs:4'));
        $this->assertStringContainsString('action_required', (string) $this->row('reach:audit_logs:4')['error_message']);

        $this->next = ['status' => 503, 'body' => null];
        $this->client()->event('reach.bot.x', [], 'reach:audit_logs:5');
        Database::connect()->query("UPDATE reach_console_sync_logs SET attempts = 11, next_attempt_at = NOW() - INTERVAL '1 second' WHERE external_id = 'reach:audit_logs:5'");
        $this->client()->deliverDue();
        $this->assertSame(['dead', 12], [$this->row('reach:audit_logs:5')['status'], (int) $this->row('reach:audit_logs:5')['attempts']]);
    }

    public function testAuditLoggerFansOutAuthEventsByAuditLogIdWithTheReachUser(): void
    {
        $db = Database::connect();
        $roleId = (int) ($db->table('reach_roles')->select('id')->get()->getRow()->id ?? 0);
        if ($roleId === 0) {
            $db->table('reach_roles')->insert(['slug' => 'zz_test', 'name' => 'ZZ Test']);
            $roleId = (int) $db->insertID();
        }
        $db->table('reach_users')->insert(['email' => 'meera@aicountly.com', 'name' => 'Meera', 'password_hash' => 'x', 'role_id' => $roleId, 'is_active' => true]);
        $uid = (int) $db->insertID();

        Services::injectMock('consoleAudit', $this->client());
        Services::auditLogger()->log(userId: $uid, action: 'auth.controller_sso_login', entityType: 'user', entityId: $uid);
        Services::auditLogger()->log(userId: $uid, action: 'unit.not_fanned_out', entityType: 'test');

        $auditId = (int) $db->table('reach_audit_logs')->where('action', 'auth.controller_sso_login')->get()->getRow()->id;
        $row = $this->row('reach:audit_logs:' . $auditId);
        $this->assertNotNull($row);
        $this->assertSame('delivered', $row['status']);
        $event = $this->sent[0]['events'][0];
        $this->assertSame(['auth.controller_sso_login', 'reach', 'meera@aicountly.com', 'Meera', 'user'],
            [$event['action'], $event['app_code'], $event['actor_email'], $event['actor_name'], $event['entity_type']]);
        $this->assertCount(1, $this->sent, 'only the fanned-out action was sent');
    }
}

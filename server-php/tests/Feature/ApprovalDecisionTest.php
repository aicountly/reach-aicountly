<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

/**
 * B2 tests #3 & #4 — Approval approve + reject actions on ApprovalController.
 */
final class ApprovalDecisionTest extends ApiTestCase
{
    private function seedPendingApproval(int $requestedBy): int
    {
        $db = \Config\Database::connect();
        // Use a different requester ID so the approver is not the same person.
        // ApprovalPolicy denies self-approval (requested_by === deciding user).
        $otherRequesterId = ($requestedBy !== 9999) ? 9999 : 9998;
        $db->table('reach_approvals')->insert([
            'subject_type' => 'blog',
            'subject_id'   => 1234,
            'summary'      => 'Test pending approval',
            'requested_by' => $otherRequesterId,
            'decision'     => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insertID();
    }

    public function testApproveDecisionRecordsDecider(): void
    {
        $headers = $this->authAs('reach_admin');
        $me = json_decode((string) $this->withHeaders($headers)->call('GET', 'v1/me')->getJSON(), true);
        $id = $this->seedPendingApproval((int) $me['data']['id']);

        $response = $this->withHeaders($headers)->call('POST', 'v1/approvals/' . $id . '/decide', [
            'decision' => 'approved',
            'note'     => 'Looks good.',
        ]);
        $this->assertSame(200, $response->response()->getStatusCode());

        $row = \Config\Database::connect()->table('reach_approvals')->where('id', $id)->get()->getRowArray();
        $this->assertSame('approved', $row['decision']);
        $this->assertNotEmpty($row['decided_at']);
    }

    public function testRejectDecisionRecordsNote(): void
    {
        $headers = $this->authAs('reach_admin');
        $me = json_decode((string) $this->withHeaders($headers)->call('GET', 'v1/me')->getJSON(), true);
        $id = $this->seedPendingApproval((int) $me['data']['id']);

        $response = $this->withHeaders($headers)->call('POST', 'v1/approvals/' . $id . '/decide', [
            'decision' => 'rejected',
            'note'     => 'Needs citations.',
        ]);
        $this->assertSame(200, $response->response()->getStatusCode());

        $row = \Config\Database::connect()->table('reach_approvals')->where('id', $id)->get()->getRowArray();
        $this->assertSame('rejected', $row['decision']);
        $this->assertSame('Needs citations.', $row['note']);
    }

    /**
     * Console gets ONE approval.decided per decision — the audit fan-out (keyed by the
     * reach_audit_logs row, with the decider, IP and the approval's subject) — and
     * console_synced_at is set once Console has it.
     */
    public function testConsoleGetsOneApprovalDecidedPerDecision(): void
    {
        $headers = $this->authAs('reach_admin');
        $me = json_decode((string) $this->withHeaders($headers)->call('GET', 'v1/me')->getJSON(), true);
        $id = $this->seedPendingApproval((int) $me['data']['id']);
        // authAs() resets shared services, so the stand-in for Console goes in after it.
        $sent = [];
        \Config\Services::injectMock('consoleAudit', new \App\Libraries\ConsoleAuditClient(
            static function (string $url, array $headers, string $body) use (&$sent): array {
                $req = json_decode($body, true);
                $sent[] = $req;
                $results = [];
                foreach ($req['events'] as $i => $e) {
                    $results[] = ['index' => $i, 'external_id' => $e['external_id'], 'status' => 'stored', 'id' => 50 + $i];
                }

                return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ['results' => $results]])];
            },
            'https://console.test/api',
            'cnnk_reach',
            \Config\Database::connect(),
        ));

        $response = $this->withHeaders($headers)->call('POST', 'v1/approvals/' . $id . '/decide', [
            'decision' => 'approved',
            'note'     => 'Ship it.',
        ]);
        $this->assertSame(200, $response->response()->getStatusCode());

        $events = array_merge(...array_map(static fn (array $r): array => $r['events'], $sent));
        $decided = array_values(array_filter($events, static fn (array $e): bool => $e['action'] === 'approval.decided'));
        $this->assertCount(1, $decided, 'one approval.decided reaches Console, not two');
        $e = $decided[0];
        $this->assertStringStartsWith('reach:audit_logs:', $e['external_id']);
        $this->assertSame(['approval', (string) $id], [$e['entity_type'], (string) $e['entity_id']]);
        $this->assertSame(['blog', 1234], [$e['metadata']['metadata']['subject_type'], $e['metadata']['metadata']['subject_id']]);
        $this->assertSame('approved', $e['new_value']['decision']);
        $this->assertSame($me['data']['email'] ?? null, $e['actor_email']);
        $this->assertNotEmpty(\Config\Database::connect()->table('reach_approvals')->where('id', $id)->get()->getRow()->console_synced_at);
        \Config\Services::reset(true);
    }
}

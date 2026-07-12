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
        $db->table('reach_approvals')->insert([
            'subject_type' => 'blog',
            'subject_id'   => 1234,
            'summary'      => 'Test pending approval',
            'requested_by' => $requestedBy,
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
        $this->assertSame(200, $response->getStatusCode());

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
        $this->assertSame(200, $response->getStatusCode());

        $row = \Config\Database::connect()->table('reach_approvals')->where('id', $id)->get()->getRowArray();
        $this->assertSame('rejected', $row['decision']);
        $this->assertSame('Needs citations.', $row['note']);
    }
}

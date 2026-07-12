<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

/**
 * B2 test #5 — permission denial returns 403 and does not mutate state.
 * Uses the analyst role which has no approval.decide permission.
 */
final class PermissionEnforcementTest extends ApiTestCase
{
    public function testAnalystCannotDecideApproval(): void
    {
        $headers = $this->authAs('analyst');

        // Seed a pending approval directly.
        $db = \Config\Database::connect();
        $db->table('reach_approvals')->insert([
            'subject_type' => 'blog',
            'subject_id'   => 999,
            'summary'      => 'Analyst denial test',
            'requested_by' => null,
            'decision'     => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();

        $response = $this->withHeaders($headers)->call('POST', 'v1/approvals/' . $id . '/decide', [
            'decision' => 'approved',
        ]);
        $this->assertSame(403, $response->getStatusCode());
        $row = $db->table('reach_approvals')->where('id', $id)->get()->getRowArray();
        $this->assertSame('pending', $row['decision']);
    }

    public function testAnalystCanReadDashboard(): void
    {
        $headers = $this->authAs('analyst');
        $response = $this->withHeaders($headers)->call('GET', 'v1/dashboard/summary');
        $this->assertSame(200, $response->getStatusCode());
    }
}

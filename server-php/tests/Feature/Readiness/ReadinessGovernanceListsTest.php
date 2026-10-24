<?php

declare(strict_types=1);

namespace Tests\Feature\Readiness;

use Tests\Support\ApiTestCase;

/**
 * Readiness overview "Privacy / AI Governance" cards → findings of that audit
 * type; "Migration Status" → v1/readiness/migrations (files vs history).
 *
 * @internal
 */
final class ReadinessGovernanceListsTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function fetch(string $path): array
    {
        $headers = $this->authAs('super_admin');
        $this->withRoutes([
            ['GET', 'v1/readiness/migrations', '\App\Controllers\Api\V1\Readiness\MigrationStatusController::index', ['filter' => ['jwt', 'permission:readiness.read']]],
        ]);
        $res = $this->withHeaders($headers)->get($path);
        $res->assertStatus(200);

        return json_decode((string) $res->getJSON(), true)['data'];
    }

    public function testFindingsFilterByAuditType(): void
    {
        $db = \Config\Database::connect();
        $db->table('reach_actors')->insert(['actor_type' => 'system', 'display_name' => 'tenant']);
        $actor = (int) $db->insertID();
        foreach (['privacy' => 2, 'ai_governance' => 1, 'security' => 1] as $type => $n) {
            $db->table('reach_readiness_audit_runs')->insert(['tenant_id' => $actor, 'audit_type' => $type, 'status' => 'completed']);
            $run = (int) $db->insertID();
            for ($i = 0; $i < $n; $i++) {
                $db->table('reach_readiness_findings')->insert([
                    'audit_run_id' => $run, 'severity' => 'high', 'category' => $type,
                    'title' => "{$type} {$i}", 'description' => 'test',
                ]);
            }
        }

        $this->assertCount(2, $this->fetch('v1/readiness/findings?audit_type=privacy'));
        $this->assertCount(1, $this->fetch('v1/readiness/findings?audit_type=ai_governance'));
        $this->assertCount(3, $this->fetch('v1/readiness/findings?audit_type=privacy,ai_governance'));
        $this->assertCount(4, $this->fetch('v1/readiness/findings?audit_type=bogus'));
    }

    public function testMigrationStatusListsEveryFileAgainstHistory(): void
    {
        $data = $this->fetch('v1/readiness/migrations');
        $files = glob(APPPATH . 'Database/Migrations/*.php');

        $this->assertSame(count($files), $data['summary']['total']);
        // The test DB is fully migrated.
        $this->assertSame($data['summary']['total'], $data['summary']['applied']);
        $this->assertSame(0, $data['summary']['pending']);
        $this->assertNotNull($data['summary']['last_applied_at']);
        $this->assertSame('applied', $data['items'][0]['state']);
        $this->assertNotNull($data['items'][0]['batch']);
        $this->assertSame([], $this->fetch('v1/readiness/migrations?state=pending')['items']);
    }
}

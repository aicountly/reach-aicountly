<?php

declare(strict_types=1);

namespace Tests\Feature\Readiness;

use Tests\Support\ApiTestCase;

/**
 * GET v1/readiness/findings?severity=&resolution_status= — the operations
 * dashboard's "Open critical / high" cards link here with the release-blocker
 * scope (open + in_progress), so the list must accept comma lists.
 *
 * @internal
 */
final class FindingsListFilterTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        $db->table('reach_actors')->insert(['actor_type' => 'system', 'display_name' => 'tenant']);
        $actor = (int) $db->insertID();
        $db->table('reach_readiness_audit_runs')->insert(['tenant_id' => $actor, 'audit_type' => 'security', 'status' => 'completed']);
        $run = (int) $db->insertID();
        foreach ([
            ['critical', 'open'], ['critical', 'in_progress'], ['critical', 'resolved'], ['high', 'open'], ['low', 'open'],
        ] as $i => [$severity, $status]) {
            $db->table('reach_readiness_findings')->insert([
                'audit_run_id' => $run, 'severity' => $severity, 'category' => 'security',
                'title' => "Finding {$i}", 'description' => 'test', 'resolution_status' => $status,
            ]);
        }
    }

    private function list(string $query = ''): array
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->get('v1/readiness/findings' . $query);
        $res->assertStatus(200);

        return json_decode($res->getJSON(), true)['data'];
    }

    public function testOpenCriticalMatchesTheBlockerScope(): void
    {
        $rows = $this->list('?severity=critical&resolution_status=open,in_progress');
        $this->assertCount(2, $rows);
        $this->assertSame(['critical'], array_values(array_unique(array_column($rows, 'severity'))));
    }

    public function testSeverityListAndSingleValues(): void
    {
        $this->assertCount(4, $this->list('?severity=critical,high'));
        $this->assertCount(1, $this->list('?severity=high'));
        $this->assertCount(3, $this->list('?resolution_status=open'));
    }

    public function testUnknownValuesAreIgnored(): void
    {
        $this->assertCount(5, $this->list('?severity=bogus'));
        $this->assertCount(1, $this->list('?severity=low,bogus'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Readiness;

use Tests\Support\ApiTestCase;

/**
 * Operations dashboard "Recommendation Backlog" / "Active Workflows" →
 * v1/readiness/recommendations | workflows → recommendation detail.
 * The list scopes must count what the summary counts.
 *
 * The routes are registered here (withRoutes) until they land in Routes.php;
 * Routes.php is still loaded alongside them, so the summary route is real.
 *
 * @internal
 */
final class RefreshBacklogDrillTest extends ApiTestCase
{
    private int $tenant;
    private int $identity;
    private int $otherIdentity;
    /** @var array<string, int> */
    private array $recs = [];
    private int $activeWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();

        $db->table('reach_actors')->insert(['actor_type' => 'system', 'display_name' => 'tenant']);
        $this->tenant = (int) $db->insertID();

        $db->table('reach_content_identities')->insert(['tenant_id' => $this->tenant, 'content_type' => 'blog', 'source_id' => 9001, 'canonical_url' => 'https://example.test/blogs/a']);
        $this->identity = (int) $db->insertID();
        $db->table('reach_content_identities')->insert(['tenant_id' => $this->tenant, 'content_type' => 'blog', 'source_id' => 9002, 'canonical_url' => 'https://example.test/blogs/b']);
        $this->otherIdentity = (int) $db->insertID();

        $db->table('reach_refresh_policies')->insert(['tenant_id' => $this->tenant, 'name' => 'Blog policy', 'content_type' => 'blog']);
        $policy = (int) $db->insertID();
        $db->table('reach_refresh_policy_versions')->insert(['policy_id' => $policy, 'version_number' => 1]);
        $version = (int) $db->insertID();

        $snapshots = [];
        foreach ([$this->identity, $this->otherIdentity] as $cid) {
            $db->table('reach_refresh_evidence_snapshots')->insert([
                'tenant_id' => $this->tenant, 'content_identity_id' => $cid, 'policy_version_id' => $version,
                'evidence_date' => '2026-10-01', 'window_days' => 28,
                'evidence_packet' => json_encode(['search' => ['clicks_trend' => -0.4]]),
                'completeness_score' => 0.75, 'missing_domains' => json_encode(['engagement']),
            ]);
            $snapshots[$cid] = (int) $db->insertID();
        }

        foreach ([
            'recommended_high' => [$this->identity, 'recommended', 'high'],
            'triaged_low'      => [$this->otherIdentity, 'triaged', 'low'],
            'accepted_crit'    => [$this->identity, 'accepted', 'critical'],
            'rejected_med'     => [$this->otherIdentity, 'rejected', 'medium'],
        ] as $key => [$cid, $status, $risk]) {
            $db->table('reach_refresh_recommendations')->insert([
                'tenant_id' => $this->tenant, 'content_identity_id' => $cid, 'policy_version_id' => $version,
                'evidence_snapshot_id' => $snapshots[$cid], 'status' => $status, 'risk_classification' => $risk, 'confidence' => 0.75,
            ]);
            $this->recs[$key] = (int) $db->insertID();
        }

        foreach ([['declining_clicks', 0.4, 0.12, 0.048], ['worsening_position', 0.2, 0.15, 0.03]] as [$factor, $raw, $weight, $contribution]) {
            $db->table('reach_refresh_score_components')->insert([
                'recommendation_id' => $this->recs['accepted_crit'], 'factor' => $factor, 'raw_value' => $raw,
                'weight' => $weight, 'contribution' => $contribution, 'evidence_source' => 'google_search_console', 'scoring_version' => '1.0',
            ]);
        }

        $db->table('reach_refresh_workflows')->insert([
            'tenant_id' => $this->tenant, 'recommendation_id' => $this->recs['accepted_crit'],
            'content_identity_id' => $this->identity, 'status' => 'published', 'refresh_objective' => 'Win back clicks',
        ]);
        $this->activeWorkflow = (int) $db->insertID();
        $db->table('reach_refresh_workflows')->insert([
            'tenant_id' => $this->tenant, 'recommendation_id' => $this->recs['rejected_med'],
            'content_identity_id' => $this->otherIdentity, 'status' => 'cancelled',
        ]);

        $db->table('reach_refresh_briefs')->insert([
            'workflow_id' => $this->activeWorkflow, 'evidence_snapshot_id' => $snapshots[$this->identity],
            'refresh_objective' => 'Win back clicks', 'key_changes' => json_encode(['Update pricing table']), 'created_by' => $this->tenant,
        ]);
        $db->table('reach_refresh_publication_links')->insert([
            'workflow_id' => $this->activeWorkflow, 'idempotency_key' => 'k-1', 'delivery_status' => 'delivered',
        ]);
        $link = (int) $db->insertID();
        $db->table('reach_refresh_outcome_windows')->insert([
            'publication_link_id' => $link, 'content_identity_id' => $this->identity,
            'baseline_from' => '2026-09-01', 'baseline_to' => '2026-09-28', 'post_from' => '2026-10-01', 'post_to' => '2026-10-28',
        ]);
    }

    /** @return array<string, mixed> */
    private function fetch(string $path): array
    {
        $headers = $this->authAs('super_admin');
        // After authAs(): it resets services, which would drop these routes.
        $this->withRoutes([
            ['GET', 'v1/readiness/recommendations', '\App\Controllers\Api\V1\Refresh\RefreshRecommendationController::index', ['filter' => ['jwt', 'permission:refresh.read']]],
            ['GET', 'v1/readiness/recommendations/(:num)', '\App\Controllers\Api\V1\Refresh\RefreshRecommendationController::show/$1', ['filter' => ['jwt', 'permission:refresh.read']]],
            ['GET', 'v1/readiness/workflows', '\App\Controllers\Api\V1\Refresh\RefreshRecommendationController::workflows', ['filter' => ['jwt', 'permission:refresh.read']]],
        ]);
        $sep = str_contains($path, '?') ? '&' : '?';
        $res = $this->withHeaders($headers)->get($path . $sep . 'tenant_id=' . $this->tenant);

        return ['status' => $res->response()->getStatusCode()] + (json_decode((string) $res->getJSON(), true) ?? []);
    }

    public function testBacklogAndActiveWorkflowCardsMatchTheirLists(): void
    {
        $summary = $this->fetch('v1/readiness/operations/summary')['data']['summary'];
        $this->assertSame(2, $summary['recommendation_backlog']);
        $this->assertSame(1, $summary['active_workflows']);

        $backlog = $this->fetch('v1/readiness/recommendations?backlog=1')['data'];
        $this->assertSame($summary['recommendation_backlog'], $backlog['total']);
        $this->assertSame($backlog['total'], $this->fetch('v1/readiness/recommendations?status=recommended,triaged')['data']['total']);
        // Highest risk first.
        $this->assertSame([$this->recs['recommended_high'], $this->recs['triaged_low']], array_column($backlog['items'], 'id'));

        $active = $this->fetch('v1/readiness/workflows?active=1')['data'];
        $this->assertSame($summary['active_workflows'], $active['total']);
        $this->assertSame($this->activeWorkflow, $active['items'][0]['id']);
        $this->assertSame('https://example.test/blogs/a', $active['items'][0]['canonical_url']);
    }

    public function testListFilters(): void
    {
        $this->assertSame([$this->recs['accepted_crit']], array_column($this->fetch('v1/readiness/recommendations?risk=critical')['data']['items'], 'id'));
        $byContent = $this->fetch('v1/readiness/recommendations?content_identity_id=' . $this->otherIdentity)['data'];
        $this->assertSame(2, $byContent['total']);
        // Unknown values are ignored rather than matching nothing.
        $this->assertSame(4, $this->fetch('v1/readiness/recommendations?status=bogus')['data']['total']);
        $this->assertSame(1, $this->fetch('v1/readiness/workflows?status=cancelled')['data']['total']);

        $row = $this->fetch('v1/readiness/recommendations?risk=critical')['data']['items'][0];
        $this->assertSame($this->activeWorkflow, $row['workflow_id']);
        $this->assertSame('published', $row['workflow_status']);
        $this->assertEqualsWithDelta(0.078, $row['total_score'], 0.0001);
    }

    public function testDetailCarriesScoreEvidenceWorkflowAndOutcomes(): void
    {
        $res = $this->fetch('v1/readiness/recommendations/' . $this->recs['accepted_crit']);
        $this->assertSame(200, $res['status']);
        $d = $res['data'];

        $this->assertSame(['declining_clicks', 'worsening_position'], array_column($d['score_components'], 'factor'));
        $this->assertEqualsWithDelta(0.078, $d['total_score'], 0.0001);
        $this->assertSame(['engagement'], $d['evidence']['missing_domains']);
        $this->assertSame(-0.4, $d['evidence']['evidence_packet']['search']['clicks_trend']);
        $this->assertSame('https://example.test/blogs/a', $d['content']['canonical_url']);

        $this->assertCount(1, $d['workflows']);
        $wf = $d['workflows'][0];
        $this->assertSame(['Update pricing table'], $wf['brief']['key_changes']);
        $this->assertCount(1, $wf['publication_links']);
        $this->assertCount(1, $wf['publication_links'][0]['outcome_windows']);
        $this->assertSame([$this->recs['recommended_high']], array_column($d['related'], 'id'));
    }

    public function testUnknownRecommendationIs404(): void
    {
        $this->assertSame(404, $this->fetch('v1/readiness/recommendations/999999')['status']);
    }
}

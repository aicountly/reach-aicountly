<?php

namespace Tests\Feature\Publishing;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Cover for the path from a red Publishing card to the failures behind it.
 *
 * The dashboard reported "failed 22" over the whole deployments table —
 * knowledge-base rows included — and linked to Ready to Publish, which lists
 * approved drafts and cannot show a deployment at all. The deployments
 * endpoint had no status or content-type filter, so even arriving at the right
 * page there was no way to isolate the failures.
 *
 * These tests pin the blog scoping, the recency window that decides the card's
 * status, and both filters the list page now sends.
 */
final class DeploymentVisibilityApiTest extends ApiTestCase
{
    private int $connectionId = 0;

    private function connection(): int
    {
        if ($this->connectionId > 0) {
            return $this->connectionId;
        }

        $db = Database::connect();
        $db->table('reach_publication_connections')->insert([
            'connection_key' => 'test-site',
            'display_name'   => 'Test Site',
            'base_url'       => 'https://example.test',
        ]);

        return $this->connectionId = (int) $db->insertID();
    }

    /** @return array{0:int,1:int} content item id, current version id */
    private function seedContent(string $title, string $type = 'blog'): array
    {
        $db = Database::connect();
        $db->table('reach_content_items')->insert([
            'title'           => $title,
            'slug'            => strtolower(str_replace(' ', '-', $title)),
            'content_type'    => $type,
            'workflow_status' => 'approved',
        ]);
        $itemId = (int) $db->insertID();

        $db->table('reach_content_versions')->insert([
            'content_item_id' => $itemId,
            'version_number'  => 1,
            'title'           => $title,
            'is_current'      => true,
        ]);

        return [$itemId, (int) $db->insertID()];
    }

    private function seedDeployment(string $title, string $status, string $type = 'blog', string $updatedAt = 'now', array $extra = []): int
    {
        [$itemId, $versionId] = $this->seedContent($title, $type);
        $db = Database::connect();

        $db->table('reach_publication_deployments')->insert(array_merge([
            'content_item_id'    => $itemId,
            'content_version_id' => $versionId,
            'connection_id'      => $this->connection(),
            'operation'          => 'publish',
            'status'             => $status,
            'idempotency_key'    => bin2hex(random_bytes(12)),
            'updated_at'         => date('Y-m-d H:i:s', strtotime($updatedAt)),
        ], $extra));

        return (int) $db->insertID();
    }

    private function overviewPublishing(array $headers): array
    {
        $response = $this->withHeaders($headers)->call('GET', 'v1/blog-command-centre/overview');
        $this->assertSame(200, $response->response()->getStatusCode(), $response->response()->getBody());

        return json_decode($response->response()->getBody(), true)['data']['publishing'] ?? [];
    }

    private function deployments(array $headers, string $query = ''): array
    {
        $response = $this->withHeaders($headers)->call('GET', 'v1/publishing/deployments' . $query);
        $this->assertSame(200, $response->response()->getStatusCode(), $response->response()->getBody());

        return json_decode($response->response()->getBody(), true)['data'] ?? [];
    }

    public function testOverviewCountsOnlyBlogDeployments(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Blog failure', 'failed', 'blog');
        $this->seedDeployment('Blog live', 'published', 'blog');
        // Knowledge-base rows must not inflate a Blog dashboard.
        $this->seedDeployment('KB failure', 'failed', 'knowledge_base');
        $this->seedDeployment('KB failure two', 'failed', 'knowledge_base');

        $publishing = $this->overviewPublishing($headers);

        $this->assertSame(1, $publishing['failed'], 'Knowledge-base failures must not be counted here');
        $this->assertSame(1, $publishing['published']);
    }

    public function testOldFailuresDoNotCountAsRecent(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Ancient failure', 'failed', 'blog', '-60 days');

        $publishing = $this->overviewPublishing($headers);

        $this->assertSame(1, $publishing['failed'], 'Still visible as an unresolved lifetime total');
        $this->assertSame(0, $publishing['failed_recent'], 'But not recent, so the card must not read ERROR');
    }

    public function testRecentFailuresAreReportedSeparately(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Fresh failure', 'failed', 'blog', '-1 day');
        $this->seedDeployment('Ancient failure', 'failed', 'blog', '-60 days');

        $publishing = $this->overviewPublishing($headers);

        $this->assertSame(2, $publishing['failed']);
        $this->assertSame(1, $publishing['failed_recent']);
    }

    public function testInFlightWorkIsVisible(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Queued', 'queued', 'blog');
        $this->seedDeployment('Sending', 'sending', 'blog');
        $this->seedDeployment('Awaiting verification', 'verification_pending', 'blog');

        $publishing = $this->overviewPublishing($headers);

        $this->assertSame(3, $publishing['in_flight'], 'Work in progress was previously invisible on the card');
    }

    public function testBlockedDeploymentsCountAsFailures(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Blocked publish', 'blocked', 'blog');

        $this->assertSame(1, $this->overviewPublishing($headers)['failed']);
    }

    public function testDeploymentsCanBeFilteredToFailures(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Failed one', 'failed', 'blog');
        $this->seedDeployment('Blocked one', 'blocked', 'blog');
        $this->seedDeployment('Happy one', 'published', 'blog');

        $all = $this->deployments($headers);
        $this->assertSame(3, $all['total']);

        $failed = $this->deployments($headers, '?status=failed,blocked');
        $this->assertSame(2, $failed['total'], 'The dashboard deep-link must isolate the failures');
        foreach ($failed['items'] as $row) {
            $this->assertContains($row['status'], ['failed', 'blocked']);
        }
    }

    public function testDeploymentsCanBeFilteredByContentType(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Blog one', 'failed', 'blog');
        $this->seedDeployment('KB one', 'failed', 'knowledge_base');

        $blogOnly = $this->deployments($headers, '?content_type=blog');
        $this->assertSame(1, $blogOnly['total']);
        $this->assertSame('blog', $blogOnly['items'][0]['content_type']);
    }

    public function testFailureReasonIsReturnedForTheList(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Rejected by site', 'failed', 'blog', 'now', [
            'error_category' => 'connector_rejected',
            'redacted_error' => 'Public site returned 422: slug already in use',
        ]);

        $row = $this->deployments($headers, '?status=failed')['items'][0];

        $this->assertSame('connector_rejected', $row['error_category']);
        $this->assertStringContainsString('slug already in use', $row['redacted_error']);
    }

    public function testUnknownStatusFilterReturnsNothingRatherThanEverything(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedDeployment('Failed one', 'failed', 'blog');

        $this->assertSame(0, $this->deployments($headers, '?status=not_a_status')['total']);
    }
}

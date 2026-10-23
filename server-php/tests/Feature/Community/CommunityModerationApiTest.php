<?php

namespace Tests\Feature\Community;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Feature tests for community moderation queue API.
 */
final class CommunityModerationApiTest extends ApiTestCase
{
    public function testModerationQueueRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/moderation/queue');
        $this->assertSame(401, $response->response()->getStatusCode());
    }

    public function testModerationQueueReturnsData(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/moderation/queue');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
    }

    public function testResolveNonExistentFindingReturns200OrError(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/moderation/999999/resolve', [
            'resolution_note' => 'no-op',
        ]);
        // DB update on non-existent row returns success (no rows affected) or error
        $this->assertContains($response->response()->getStatusCode(), [200, 404, 500]);
    }

    public function testModerationQueueRequiresModerationPermission(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/moderation/queue');
        $this->assertContains($response->response()->getStatusCode(), [401, 403]);
    }

    public function testEscalateNonExistentFindingReturns200OrError(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/moderation/999999/escalate', [
            'note' => 'test',
        ]);
        $this->assertContains($response->response()->getStatusCode(), [200, 404, 500]);
    }

    public function testModerationQueueFiltersByTypeAndSeverity(): void
    {
        $db = Database::connect();
        $db->table('reach_community_moderation_findings')->insertBatch([
            ['finding_type' => 'spam', 'severity' => 'critical', 'status' => 'open'],
            ['finding_type' => 'spam', 'severity' => 'warning', 'status' => 'open'],
            ['finding_type' => 'unsafe_links', 'severity' => 'critical', 'status' => 'open'],
            ['finding_type' => 'spam', 'severity' => 'critical', 'status' => 'resolved'],
        ]);

        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/moderation/queue?finding_type=spam&severity=critical');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);

        // Only the open spam/critical finding; the total matches the rows.
        $this->assertCount(1, $body['data']);
        $this->assertSame('spam', $body['data'][0]['finding_type']);
        $this->assertSame('critical', $body['data'][0]['severity']);
        $this->assertSame(1, $body['meta']['total']);
    }

    public function testModerationQueueIgnoresAnUnknownSeverity(): void
    {
        Database::connect()->table('reach_community_moderation_findings')
            ->insert(['finding_type' => 'spam', 'severity' => 'warning']);

        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/moderation/queue?severity=bogus');
        $body = json_decode((string) $response->getJSON(), true);

        $this->assertSame(1, $body['meta']['total']);
    }
}


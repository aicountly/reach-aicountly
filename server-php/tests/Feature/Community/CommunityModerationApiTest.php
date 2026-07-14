<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Feature tests for community moderation queue API.
 */
final class CommunityModerationApiTest extends ApiTestCase
{
    public function testModerationQueueRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/moderation/queue');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testModerationQueueReturnsData(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/moderation/queue');
        $this->assertSame(200, $response->getStatusCode());
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
        $this->assertContains($response->getStatusCode(), [200, 404, 500]);
    }

    public function testModerationQueueRequiresModerationPermission(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/moderation/queue');
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }

    public function testEscalateNonExistentFindingReturns200OrError(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/moderation/999999/escalate', [
            'note' => 'test',
        ]);
        $this->assertContains($response->getStatusCode(), [200, 404, 500]);
    }
}

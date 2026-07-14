<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Feature tests for community analytics API.
 */
final class CommunityAnalyticsApiTest extends ApiTestCase
{
    public function testAnalyticsOverviewRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/analytics/overview');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testAnalyticsOverviewReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/overview');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('published_answers', $body['data']);
        $this->assertArrayHasKey('open_moderation_flags', $body['data']);
    }

    public function testAnalyticsEngagementReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/engagement?days=7');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame(7, $body['days']);
    }

    public function testAnalyticsCoverageReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/coverage');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnalyticsCacheReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/cache');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnalyticsRequiresViewAnalyticsPermission(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/analytics/overview');
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }
}

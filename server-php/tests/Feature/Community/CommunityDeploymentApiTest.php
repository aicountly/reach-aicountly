<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Feature tests for community deployment monitor API.
 */
final class CommunityDeploymentApiTest extends ApiTestCase
{
    public function testListDeploymentsRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/deployments');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListDeploymentsReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/deployments');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
    }

    public function testGetNonExistentDeploymentReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/deployments/no-such-uuid');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRetryNonExistentDeploymentReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/deployments/no-such-uuid/retry', []);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRetryDeploymentRequiresPublishPermission(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/deployments/uuid/retry', []);
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }
}

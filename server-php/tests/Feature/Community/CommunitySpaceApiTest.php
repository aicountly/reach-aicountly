<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Feature tests for community space CRUD API.
 */
final class CommunitySpaceApiTest extends ApiTestCase
{
    public function testListSpacesRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/spaces');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListSpacesReturnsPaginatedData(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/spaces');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
    }

    public function testCreateSpaceReturns201(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/spaces', [
            'slug'  => 'test-space-' . uniqid(),
            'title' => 'Test Space',
        ]);
        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertNotEmpty($body['data']['id']);
    }

    public function testCreateSpaceValidationFailsWithoutSlug(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/spaces', [
            'title' => 'No Slug Space',
        ]);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateSpaceWithoutPermissionReturns403(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/spaces', [
            'slug'  => 'denied-space',
            'title' => 'Denied',
        ]);
        $this->assertContains($response->getStatusCode(), [403, 401]);
    }

    public function testGetNonExistentSpaceReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/spaces/nonexistent-slug-99999');
        $this->assertSame(404, $response->getStatusCode());
    }
}

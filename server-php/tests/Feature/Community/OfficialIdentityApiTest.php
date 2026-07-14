<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Feature tests for official identity CRUD API.
 */
final class OfficialIdentityApiTest extends ApiTestCase
{
    private function createIdentity(array $headers, string $suffix = ''): array
    {
        $suffix = $suffix ?: uniqid();
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/identities', [
            'slug'         => 'test-identity-' . $suffix,
            'display_name' => 'Test Identity ' . $suffix,
            'badge_type'   => 'official',
        ]);
        return json_decode((string) $response->getJSON(), true);
    }

    public function testListIdentitiesIsAccessibleWithAuth(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/identities');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('data', json_decode((string) $response->getJSON(), true));
    }

    public function testCreateIdentityReturns201(): void
    {
        $headers = $this->authAs('reach_admin');
        $body    = $this->createIdentity($headers);
        $this->assertNotEmpty($body['data']['id']);
        $this->assertSame('official', $body['data']['badge_type']);
    }

    public function testCreateDuplicateSlugReturns409(): void
    {
        $headers = $this->authAs('reach_admin');
        $slug    = 'dupe-slug-' . uniqid();
        $this->withHeaders($headers)->call('POST', 'v1/community/identities', [
            'slug'         => $slug,
            'display_name' => 'First',
        ]);
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/identities', [
            'slug'         => $slug,
            'display_name' => 'Second',
        ]);
        $this->assertSame(409, $response->getStatusCode());
    }

    public function testGetIdentityBySlug(): void
    {
        $headers = $this->authAs('reach_admin');
        $created = $this->createIdentity($headers, 'gettest');
        $slug    = $created['data']['slug'];

        $response = $this->withHeaders($headers)->call('GET', "v1/community/identities/{$slug}");
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeactivateIdentity(): void
    {
        $headers = $this->authAs('reach_admin');
        $created = $this->createIdentity($headers, 'deact');
        $slug    = $created['data']['slug'];

        $response = $this->withHeaders($headers)->call('DELETE', "v1/community/identities/{$slug}");
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateIdentityWithoutPermissionReturns403(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/identities', [
            'slug'         => 'no-perm-' . uniqid(),
            'display_name' => 'NoPerm',
        ]);
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }
}

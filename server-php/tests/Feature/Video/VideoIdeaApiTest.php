<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use Tests\Support\ApiTestCase;

/**
 * @group video
 * @group video-ideas
 */
class VideoIdeaApiTest extends ApiTestCase
{
    protected $refresh = true;

    private function asSuperAdmin(): self
    {
        return $this->withHeaders($this->authAs('super_admin'));
    }

    public function testListIdeasReturns200(): void
    {
        $resp = $this->asSuperAdmin()->call('GET', 'v1/video/ideas');
        $this->assertSame(200, $resp->response()->getStatusCode());
        $body = json_decode((string) $resp->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
    }

    public function testCreateIdeaReturns201(): void
    {
        $resp = $this->asSuperAdmin()->call('POST', 'v1/video/ideas', [
            'title'   => 'Test video idea',
            'summary' => 'A test idea summary',
            'status'  => 'draft',
        ]);
        $this->assertSame(201, $resp->response()->getStatusCode());
        $body = json_decode((string) $resp->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertNotEmpty($body['data']['uuid'] ?? '');
    }

    public function testGetIdeaByUuidReturns200(): void
    {
        $create = $this->asSuperAdmin()->call('POST', 'v1/video/ideas', [
            'title'  => 'Read idea test',
            'status' => 'draft',
        ]);
        $uuid = json_decode((string) $create->getJSON(), true)['data']['uuid'] ?? null;
        $this->assertNotNull($uuid, 'Create must return a uuid');

        $resp = $this->asSuperAdmin()->call('GET', "v1/video/ideas/{$uuid}");
        $this->assertSame(200, $resp->response()->getStatusCode());
    }

    public function testGetNonExistentIdeaReturns404(): void
    {
        $resp = $this->asSuperAdmin()->call('GET', 'v1/video/ideas/00000000-0000-0000-0000-000000000000');
        $this->assertSame(404, $resp->response()->getStatusCode());
    }

    public function testAcceptIdeaReturns200(): void
    {
        $create = $this->asSuperAdmin()->call('POST', 'v1/video/ideas', [
            'title'  => 'Accept test idea',
            'status' => 'ready',
        ]);
        $uuid = json_decode((string) $create->getJSON(), true)['data']['uuid'] ?? null;
        $this->assertNotNull($uuid);

        $resp = $this->asSuperAdmin()->call('POST', "v1/video/ideas/{$uuid}/accept");
        $this->assertSame(200, $resp->response()->getStatusCode());
    }

    public function testConvertAcceptedIdeaCreatesProject(): void
    {
        $create = $this->asSuperAdmin()->call('POST', 'v1/video/ideas', [
            'title'  => 'Convert test idea',
            'status' => 'ready',
        ]);
        $uuid = json_decode((string) $create->getJSON(), true)['data']['uuid'] ?? null;
        $this->assertNotNull($uuid);

        $this->asSuperAdmin()->call('POST', "v1/video/ideas/{$uuid}/accept");

        $resp = $this->asSuperAdmin()->call('POST', "v1/video/ideas/{$uuid}/convert");
        $this->assertSame(201, $resp->response()->getStatusCode());
        $body = json_decode((string) $resp->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertNotEmpty($body['data']['uuid'] ?? '');
    }

    public function testListIdeasRequiresVideoReadPermission(): void
    {
        $resp = $this->call('GET', 'v1/video/ideas');
        $this->assertContains($resp->response()->getStatusCode(), [401, 403]);
    }
}

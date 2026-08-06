<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content item deletion: first delete archives, second delete removes.
 */
final class ContentDeleteTest extends ApiTestCase
{
    public function testFirstDeleteArchivesAndSecondDeleteRemoves(): void
    {
        $headers = $this->authAs('super_admin');
        $id      = $this->createItem($headers, 'Content delete test');

        // First delete → archived, still readable.
        $first = $this->withHeaders($headers)->call('DELETE', 'v1/content/items/' . $id);
        $this->assertSame(200, $first->response()->getStatusCode());
        $firstBody = json_decode((string) $first->getJSON(), true);
        $this->assertTrue($firstBody['data']['deleted']);
        $this->assertFalse($firstBody['data']['permanent']);

        $show = $this->withHeaders($headers)->call('GET', 'v1/content/items/' . $id);
        $this->assertSame(200, $show->response()->getStatusCode());
        $this->assertSame('archived', json_decode((string) $show->getJSON(), true)['data']['workflow_status']);

        // Second delete → permanent, gone from the API.
        $second = $this->withHeaders($headers)->call('DELETE', 'v1/content/items/' . $id);
        $this->assertSame(200, $second->response()->getStatusCode());
        $this->assertTrue(json_decode((string) $second->getJSON(), true)['data']['permanent']);

        $gone = $this->withHeaders($headers)->call('GET', 'v1/content/items/' . $id);
        $this->assertSame(404, $gone->response()->getStatusCode());
    }

    public function testDeletedItemDropsOutOfTheListing(): void
    {
        $headers = $this->authAs('super_admin');
        $id      = $this->createItem($headers, 'Content delete listing test');

        $this->withHeaders($headers)->call('DELETE', 'v1/content/items/' . $id);
        $this->withHeaders($headers)->call('DELETE', 'v1/content/items/' . $id);

        $list = $this->withHeaders($headers)->call('GET', 'v1/content/items?limit=100');
        $ids  = array_column(json_decode((string) $list->getJSON(), true)['data']['items'] ?? [], 'id');
        $this->assertNotContains($id, array_map('intval', $ids));
    }

    public function testDeleteRequiresEditPermission(): void
    {
        $adminHeaders = $this->authAs('super_admin');
        $id           = $this->createItem($adminHeaders, 'Content delete permission test');

        $viewerHeaders = $this->authAs('viewer');
        $res           = $this->withHeaders($viewerHeaders)->call('DELETE', 'v1/content/items/' . $id);
        $this->assertContains($res->response()->getStatusCode(), [401, 403]);

        $show = $this->withHeaders($adminHeaders)->call('GET', 'v1/content/items/' . $id);
        $this->assertSame(200, $show->response()->getStatusCode());
    }

    public function testDeleteUnknownItemReturns404(): void
    {
        $headers = $this->authAs('super_admin');
        $res     = $this->withHeaders($headers)->call('DELETE', 'v1/content/items/99999999');
        $this->assertSame(404, $res->response()->getStatusCode());
    }

    private function createItem(array $headers, string $title): int
    {
        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => $title,
            'content_type' => 'blog',
        ]);
        $this->assertSame(201, $res->response()->getStatusCode());

        return (int) json_decode((string) $res->getJSON(), true)['data']['id'];
    }
}

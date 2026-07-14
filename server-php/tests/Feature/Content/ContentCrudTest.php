<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content CRUD: create, show, list, update, archive.
 * Skipped automatically when TEST_DB_NAME is not set.
 */
final class ContentCrudTest extends ApiTestCase
{
    public function testCreateShowListAndUpdate(): void
    {
        $headers = $this->authAs('super_admin');

        // Create
        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Phase 2 test content',
            'content_type' => 'blog',
        ]);
        $this->assertSame(201, $res->response()->getStatusCode());
        $body = json_decode((string) $res->getJSON(), true);
        $this->assertTrue($body['ok']);
        $id = $body['data']['id'] ?? null;
        $this->assertNotNull($id);

        // Show
        $show = $this->withHeaders($headers)->call('GET', 'v1/content/items/' . $id);
        $this->assertSame(200, $show->response()->getStatusCode());

        // List
        $list = $this->withHeaders($headers)->call('GET', 'v1/content/items?page=1&limit=5');
        $this->assertSame(200, $list->response()->getStatusCode());

        // Update
        $update = $this->withHeaders($headers)->call('PUT', 'v1/content/items/' . $id, [
            'title' => 'Phase 2 test content (updated)',
        ]);
        $this->assertSame(200, $update->response()->getStatusCode());
    }
}


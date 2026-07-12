<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

/**
 * B2 test #2 — Blog CRUD happy path (super_admin).
 */
final class BlogCrudTest extends ApiTestCase
{
    public function testCreateShowListAndUpdate(): void
    {
        $headers = $this->authAs('super_admin');

        // Create
        $created = $this->withHeaders($headers)->call('POST', 'v1/blog/posts', [
            'title'   => 'Phase 0 test blog',
            'slug'    => 'phase-0-test-blog-' . random_int(1000, 9999),
            'content' => 'Body of the blog post.',
            'category'=> 'engineering',
        ]);
        $this->assertSame(200, $created->getStatusCode());
        $body = json_decode((string) $created->getJSON(), true);
        $this->assertTrue($body['ok']);
        $id = $body['data']['id'] ?? null;
        $this->assertNotNull($id);

        // Show
        $show = $this->withHeaders($headers)->call('GET', 'v1/blog/posts/' . $id);
        $this->assertSame(200, $show->getStatusCode());

        // List
        $list = $this->withHeaders($headers)->call('GET', 'v1/blog/posts?page=1&limit=5');
        $this->assertSame(200, $list->getStatusCode());
        $listBody = json_decode((string) $list->getJSON(), true);
        $this->assertTrue($listBody['ok']);
        $this->assertArrayHasKey('items', $listBody['data']);

        // Update
        $update = $this->withHeaders($headers)->call('PUT', 'v1/blog/posts/' . $id, [
            'title' => 'Phase 0 test blog (updated)',
        ]);
        $this->assertSame(200, $update->getStatusCode());
    }
}

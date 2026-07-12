<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content knowledge mapping feature tests.
 */
final class ContentMappingTest extends ApiTestCase
{
    public function testGetMappingsForNewItem(): void
    {
        $headers = $this->authAs('super_admin');

        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Mapping test',
            'content_type' => 'blog',
        ]);
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        $mappings = $this->withHeaders($headers)->call('GET', "v1/content/items/{$id}/mappings");
        $this->assertSame(200, $mappings->getStatusCode());
        $body = json_decode((string) $mappings->getJSON(), true);
        $this->assertTrue($body['ok']);
    }
}

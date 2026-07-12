<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content versioning feature tests.
 */
final class ContentVersionTest extends ApiTestCase
{
    public function testVersionCreatedOnContentCreate(): void
    {
        $headers = $this->authAs('super_admin');

        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Version test',
            'content_type' => 'blog',
            'body_html'    => '<p>First version</p>',
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        $versions = $this->withHeaders($headers)->call('GET', "v1/content/items/{$id}/versions");
        $this->assertSame(200, $versions->getStatusCode());
        $vBody = json_decode((string) $versions->getJSON(), true);
        $this->assertTrue($vBody['ok']);
    }
}

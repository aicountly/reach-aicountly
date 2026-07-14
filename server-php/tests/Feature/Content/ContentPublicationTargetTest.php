<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Publication target CRUD feature tests.
 */
final class ContentPublicationTargetTest extends ApiTestCase
{
    public function testCreateAndListPublicationTargets(): void
    {
        $headers = $this->authAs('super_admin');

        $create = $this->withHeaders($headers)->call('POST', 'v1/content/publication-targets', [
            'channel'   => 'aicountly_website',
            'name'      => 'Main Website',
            'is_active' => true,
        ]);
        $this->assertSame(201, $create->response()->getStatusCode());

        $list = $this->withHeaders($headers)->call('GET', 'v1/content/publication-targets');
        $this->assertSame(200, $list->response()->getStatusCode());
    }
}


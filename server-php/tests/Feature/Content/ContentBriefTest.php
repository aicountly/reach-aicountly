<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content brief feature tests.
 */
final class ContentBriefTest extends ApiTestCase
{
    public function testCreateAndGetBrief(): void
    {
        $headers = $this->authAs('super_admin');

        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Brief test',
            'content_type' => 'blog',
        ]);
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        $brief = $this->withHeaders($headers)->call('PUT', "v1/content/items/{$id}/brief", [
            'objective'       => 'Test brief objective',
            'primary_keyword' => 'test keyword',
            'tone'            => 'professional',
            'word_count_min'  => 800,
            'word_count_max'  => 1200,
        ]);
        $this->assertSame(200, $brief->response()->getStatusCode());

        $get = $this->withHeaders($headers)->call('GET', "v1/content/items/{$id}/brief");
        $this->assertSame(200, $get->response()->getStatusCode());
    }
}


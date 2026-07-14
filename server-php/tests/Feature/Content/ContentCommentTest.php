<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content comments feature tests.
 */
final class ContentCommentTest extends ApiTestCase
{
    public function testCreateAndListComments(): void
    {
        $headers = $this->authAs('super_admin');

        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Comment test item',
            'content_type' => 'blog',
        ]);
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        $comment = $this->withHeaders($headers)->call('POST', "v1/content/items/{$id}/comments", [
            'body_html' => '<p>Test comment</p>',
        ]);
        $this->assertSame(201, $comment->response()->getStatusCode());

        $list = $this->withHeaders($headers)->call('GET', "v1/content/items/{$id}/comments");
        $this->assertSame(200, $list->response()->getStatusCode());
    }
}


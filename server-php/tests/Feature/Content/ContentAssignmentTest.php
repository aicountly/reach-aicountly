<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content assignment feature tests.
 */
final class ContentAssignmentTest extends ApiTestCase
{
    public function testAssignAndListAssignments(): void
    {
        $headers = $this->authAs('super_admin');

        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Assignment test',
            'content_type' => 'blog',
        ]);
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        // Get a user ID for assignment
        $userId = 1; // super_admin created in authAs

        $assign = $this->withHeaders($headers)->call('POST', "v1/content/items/{$id}/assignments", [
            'user_id' => $userId,
            'role'    => 'reviewer',
        ]);
        $this->assertSame(200, $assign->response()->getStatusCode());

        $list = $this->withHeaders($headers)->call('GET', "v1/content/items/{$id}/assignments");
        $this->assertSame(200, $list->response()->getStatusCode());
    }
}


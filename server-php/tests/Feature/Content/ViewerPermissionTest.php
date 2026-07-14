<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Tests that viewer role can read content items and versions but cannot
 * create, update, or access restricted endpoints.
 */
final class ViewerPermissionTest extends ApiTestCase
{
    public function testViewerCanListContent(): void
    {
        $headers = $this->authAs('viewer');
        $res = $this->withHeaders($headers)->call('GET', 'v1/content/items');
        $this->assertSame(200, $res->response()->getStatusCode());
    }

    public function testViewerCannotCreateContent(): void
    {
        $headers = $this->authAs('viewer');
        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Should fail',
            'content_type' => 'blog',
        ]);
        $this->assertGreaterThanOrEqual(400, $res->response()->getStatusCode());
    }

    public function testViewerCannotAccessApprovalQueue(): void
    {
        $headers = $this->authAs('viewer');
        $res = $this->withHeaders($headers)->call('GET', 'v1/approval-queue');
        $this->assertGreaterThanOrEqual(400, $res->response()->getStatusCode());
    }
}


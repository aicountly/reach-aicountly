<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content workflow transitions via the API.
 * Skipped automatically when TEST_DB_NAME is not set.
 */
final class ContentWorkflowTest extends ApiTestCase
{
    public function testSubmitAndReview(): void
    {
        $headers = $this->authAs('super_admin');

        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Workflow test',
            'content_type' => 'blog',
        ]);
        $this->assertSame(201, $res->response()->getStatusCode());
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        // Move through workflow: idea → brief
        $t1 = $this->withHeaders($headers)->call('POST', "v1/content/items/{$id}/transition", [
            'status' => 'brief',
        ]);
        $this->assertSame(200, $t1->response()->getStatusCode());

        // brief → draft
        $t2 = $this->withHeaders($headers)->call('POST', "v1/content/items/{$id}/transition", [
            'status' => 'draft',
        ]);
        $this->assertSame(200, $t2->response()->getStatusCode());
    }

    public function testInvalidTransitionIsRejected(): void
    {
        $headers = $this->authAs('super_admin');

        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Invalid transition test',
            'content_type' => 'blog',
        ]);
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        // idea → approved is not a valid transition
        $bad = $this->withHeaders($headers)->call('POST', "v1/content/items/{$id}/transition", [
            'status' => 'approved',
        ]);
        // Should return error (422 or 400)
        $this->assertGreaterThanOrEqual(400, $bad->response()->getStatusCode());
    }
}


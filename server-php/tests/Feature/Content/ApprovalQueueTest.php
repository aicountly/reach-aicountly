<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Approval queue API feature tests.
 */
final class ApprovalQueueTest extends ApiTestCase
{
    public function testQueueListAndStats(): void
    {
        $headers = $this->authAs('super_admin');

        $list = $this->withHeaders($headers)->call('GET', 'v1/approval-queue');
        $this->assertSame(200, $list->response()->getStatusCode());
        $body = json_decode((string) $list->getJSON(), true);
        $this->assertTrue($body['ok']);
        $this->assertArrayHasKey('items', $body['data']);

        $stats = $this->withHeaders($headers)->call('GET', 'v1/approval-queue/stats');
        $this->assertSame(200, $stats->response()->getStatusCode());
    }

    public function testQueueRequiresPermission(): void
    {
        // viewer lacks content.review → 403
        $headers = $this->authAs('viewer');
        $res = $this->withHeaders($headers)->call('GET', 'v1/approval-queue');
        $this->assertGreaterThanOrEqual(400, $res->response()->getStatusCode());
    }
}


<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content schedule feature tests.
 */
final class ContentScheduleTest extends ApiTestCase
{
    public function testScheduleRequiresApprovedContent(): void
    {
        $headers = $this->authAs('super_admin');

        // Create content item in 'idea' state
        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Schedule test',
            'content_type' => 'blog',
        ]);
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        // Attempt to schedule it before approval — should fail
        $schedule = $this->withHeaders($headers)->call('POST', "v1/content/items/{$id}/schedules", [
            'publication_target_id' => 999,
            'scheduled_at'          => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
        // Should fail because content is not yet approved
        $this->assertGreaterThanOrEqual(400, $schedule->response()->getStatusCode());
    }
}


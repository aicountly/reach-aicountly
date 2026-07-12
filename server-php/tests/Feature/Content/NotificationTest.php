<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Notification API feature tests.
 */
final class NotificationTest extends ApiTestCase
{
    public function testListAndCountNotifications(): void
    {
        $headers = $this->authAs('super_admin');

        $list = $this->withHeaders($headers)->call('GET', 'v1/notifications');
        $this->assertSame(200, $list->getStatusCode());
        $body = json_decode((string) $list->getJSON(), true);
        $this->assertTrue($body['ok']);
        $this->assertArrayHasKey('notifications', $body['data']);

        $count = $this->withHeaders($headers)->call('GET', 'v1/notifications/count');
        $this->assertSame(200, $count->getStatusCode());
    }
}

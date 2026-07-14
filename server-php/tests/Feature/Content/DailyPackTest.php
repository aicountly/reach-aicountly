<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Daily marketing pack API feature tests.
 */
final class DailyPackTest extends ApiTestCase
{
    public function testListAndGeneratePack(): void
    {
        $headers = $this->authAs('super_admin');

        $list = $this->withHeaders($headers)->call('GET', 'v1/content/daily-packs');
        $this->assertSame(200, $list->response()->getStatusCode());

        $generate = $this->withHeaders($headers)->call('POST', 'v1/content/daily-packs/generate', [
            'pack_date' => date('Y-m-d'),
        ]);
        $this->assertSame(200, $generate->response()->getStatusCode());
        $body = json_decode((string) $generate->getJSON(), true);
        $this->assertTrue($body['ok']);
    }

    public function testGetPackConfig(): void
    {
        $headers = $this->authAs('super_admin');
        $config = $this->withHeaders($headers)->call('GET', 'v1/content/daily-packs/config');
        $this->assertSame(200, $config->response()->getStatusCode());
    }
}


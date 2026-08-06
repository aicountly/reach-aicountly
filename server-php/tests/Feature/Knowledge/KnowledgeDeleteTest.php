<?php

namespace Tests\Feature\Knowledge;

use Tests\Support\ApiTestCase;

/**
 * Knowledge base deletion: a deleted record disappears from the API.
 */
final class KnowledgeDeleteTest extends ApiTestCase
{
    public function testDeleteMarketRemovesItFromShowAndList(): void
    {
        $headers = $this->authAs('super_admin');

        $create = $this->withHeaders($headers)->call('POST', 'v1/knowledge/markets', [
            'name'   => 'Knowledge delete test market',
            'region' => 'APAC',
        ]);
        $this->assertSame(201, $create->response()->getStatusCode());
        $id = (int) json_decode((string) $create->getJSON(), true)['data']['id'];

        $delete = $this->withHeaders($headers)->call('DELETE', 'v1/knowledge/markets/' . $id);
        $this->assertSame(200, $delete->response()->getStatusCode());
        $this->assertTrue(json_decode((string) $delete->getJSON(), true)['data']['deleted']);

        $show = $this->withHeaders($headers)->call('GET', 'v1/knowledge/markets/' . $id);
        $this->assertSame(404, $show->response()->getStatusCode());

        $list = $this->withHeaders($headers)->call('GET', 'v1/knowledge/markets?limit=100');
        $body = json_decode((string) $list->getJSON(), true)['data'];
        $ids  = array_map('intval', array_column($body['items'] ?? $body['data'] ?? [], 'id'));
        $this->assertNotContains($id, $ids);
    }

    public function testDeleteMarketRequiresKnowledgeEdit(): void
    {
        $adminHeaders = $this->authAs('super_admin');

        $create = $this->withHeaders($adminHeaders)->call('POST', 'v1/knowledge/markets', [
            'name' => 'Knowledge delete permission market',
        ]);
        $id = (int) json_decode((string) $create->getJSON(), true)['data']['id'];

        $viewerHeaders = $this->authAs('viewer');
        $res           = $this->withHeaders($viewerHeaders)->call('DELETE', 'v1/knowledge/markets/' . $id);
        $this->assertContains($res->response()->getStatusCode(), [401, 403]);

        $show = $this->withHeaders($adminHeaders)->call('GET', 'v1/knowledge/markets/' . $id);
        $this->assertSame(200, $show->response()->getStatusCode());
    }

    public function testDeleteUnknownMarketReturns404(): void
    {
        $headers = $this->authAs('super_admin');
        $res     = $this->withHeaders($headers)->call('DELETE', 'v1/knowledge/markets/99999999');
        $this->assertSame(404, $res->response()->getStatusCode());
    }
}

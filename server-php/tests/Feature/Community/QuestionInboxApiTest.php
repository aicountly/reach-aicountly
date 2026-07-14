<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Feature tests for community question inbox API.
 */
final class QuestionInboxApiTest extends ApiTestCase
{
    public function testListQuestionsRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/questions');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListQuestionsReturnsPaginatedData(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
    }

    public function testListQuestionsStatsEndpointReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions/stats');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
    }

    public function testGetNonExistentQuestionReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions/00000000-0000-0000-0000-000000000000');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListQuestionsFilterByStatus(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions?status=new');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        foreach ($body['data'] ?? [] as $q) {
            $this->assertSame('new', $q['status']);
        }
    }

    public function testListQuestionsMetaHasRequiredFields(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions');
        $body     = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('current_page', $body['meta']);
        $this->assertArrayHasKey('per_page', $body['meta']);
        $this->assertArrayHasKey('total', $body['meta']);
    }
}

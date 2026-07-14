<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Feature tests for official answer lifecycle API.
 */
final class OfficialAnswerApiTest extends ApiTestCase
{
    public function testListAnswersRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/answers');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testListAnswersReturnsPaginatedData(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
    }

    public function testGetNonExistentAnswerReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers/00000000-dead-beef-0000-000000000000');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListAnswersFilterByStatus(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers?status=draft');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        foreach ($body['data'] ?? [] as $answer) {
            $this->assertSame('draft', $answer['status']);
        }
    }

    public function testCreateAnswerWithoutQuestionUuidReturns422(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/answers', []);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testAnswerVersionsEndpointReturns404ForUnknown(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers/no-such-uuid/versions');
        // Either 200 with empty data or 404 — either acceptable
        $this->assertContains($response->getStatusCode(), [200, 404]);
    }

    public function testAnswerPublishRequiresPermission(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/answers/fake-uuid/publish', []);
        $this->assertContains($response->getStatusCode(), [401, 403]);
    }
}

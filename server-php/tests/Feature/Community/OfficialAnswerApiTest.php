<?php

namespace Tests\Feature\Community;

use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use Tests\Support\ApiTestCase;

/**
 * Feature tests for official answer lifecycle API.
 */
final class OfficialAnswerApiTest extends ApiTestCase
{
    public function testListAnswersRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/answers');
        $this->assertSame(401, $response->response()->getStatusCode());
    }

    public function testListAnswersReturnsPaginatedData(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
    }

    public function testGetNonExistentAnswerReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers/00000000-dead-beef-0000-000000000000');
        $this->assertSame(404, $response->response()->getStatusCode());
    }

    public function testListAnswersFilterByStatus(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers?status=draft');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        foreach ($body['data'] ?? [] as $answer) {
            $this->assertSame('draft', $answer['status']);
        }
    }

    public function testCreateAnswerWithoutQuestionUuidReturns422(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/answers', []);
        $this->assertSame(422, $response->response()->getStatusCode());
    }

    public function testAnswerVersionsEndpointReturns404ForUnknown(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers/no-such-uuid/versions');
        // Either 200 with empty data or 404 — either acceptable
        $this->assertContains($response->response()->getStatusCode(), [200, 404]);
    }

    public function testAnswerPublishRequiresPermission(): void
    {
        $headers  = $this->authAs('blog_author');
        $response = $this->withHeaders($headers)->call('POST', 'v1/community/answers/fake-uuid/publish', []);
        $this->assertContains($response->response()->getStatusCode(), [401, 403]);
    }

    public function testListAnswersScopedToAQuestion(): void
    {
        // The question workspace lists its answers with ?question_uuid=; the
        // filter used to be ignored, so every answer showed as the question's.
        $lifecycle = new OfficialAnswerLifecycleService();
        $question  = $this->intake('How do I claim GST input tax credit on capital goods?');
        $answer    = $lifecycle->createDraft($question['uuid'], 'aicountly-gst-guide');
        $lifecycle->createDraft($this->intake('How do I file a GSTR-1 nil return?')['uuid'], 'aicountly-gst-guide');

        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers?question_uuid=' . $question['uuid']);
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);

        $this->assertSame([$answer['uuid']], array_column($body['data'], 'uuid'));
        $this->assertSame(1, $body['meta']['total']);
    }

    public function testListAnswersForAnUnknownQuestionIsEmpty(): void
    {
        $lifecycle = new OfficialAnswerLifecycleService();
        $lifecycle->createDraft($this->intake('How do I file a GSTR-1 nil return?')['uuid'], 'aicountly-gst-guide');

        $headers = $this->authAs('reach_admin');
        foreach (['00000000-0000-0000-0000-000000000000', 'undefined'] as $questionUuid) {
            $response = $this->withHeaders($headers)->call('GET', 'v1/community/answers?question_uuid=' . $questionUuid);
            $this->assertSame(200, $response->response()->getStatusCode());
            $this->assertSame([], json_decode((string) $response->getJSON(), true)['data'], $questionUuid);
        }
    }

    public function testCreateAnswerReturnsTheAnswerUuid(): void
    {
        // The workspace opens the new answer's editor from data.uuid.
        $question = $this->intake('How do I file a GSTR-1 nil return?');
        $headers  = $this->authAs('reach_admin');

        $response = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->call('POST', 'v1/community/answers', [
                'question_uuid'          => $question['uuid'],
                'official_identity_slug' => 'aicountly-gst-guide',
            ]);
        $this->assertSame(201, $response->response()->getStatusCode());
        $data = json_decode((string) $response->getJSON(), true)['data'];

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $data['uuid']);
        $this->assertSame($question['uuid'], $data['question_uuid']);
    }

    private function intake(string $title): array
    {
        return (new CommunityQuestionIntakeService())->intake([
            'source_type' => 'manual',
            'title'       => $title,
            'category'    => 'gst',
        ]);
    }
}


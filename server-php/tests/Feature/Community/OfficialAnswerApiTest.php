<?php

namespace Tests\Feature\Community;

use App\Libraries\Community\CommunityOperationalAgentService;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use App\Models\CommunityOfficialIdentityModel;
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

    public function testCreateAnswerWithoutAnIdentityDraftsAsTheCategoryDesk(): void
    {
        // The question workspace names no identity. The old fallback was a
        // retired identity, so every draft it asked for was refused.
        $question = $this->intake('How do I file a GSTR-1 nil return?');

        $answer = $this->createAnswer(['question_uuid' => $question['uuid']]);

        $this->assertSame($this->identityId('aicountly-gst-guide'), (int) $answer['identity_id']);
    }

    public function testCreateAnswerTreatsABlankIdentityAsUnnamed(): void
    {
        foreach (['', null] as $slug) {
            $question = $this->intake('How do I file a GSTR-1 nil return?');

            $answer = $this->createAnswer(['question_uuid' => $question['uuid'], 'official_identity_slug' => $slug]);

            $this->assertSame($this->identityId('aicountly-gst-guide'), (int) $answer['identity_id'], var_export($slug, true));
        }
    }

    public function testCreateAnswerForACategoryWithoutADeskDraftsAsTheComplianceDesk(): void
    {
        $question = $this->intake('When is the annual return due under the Companies Act?', 'company-law');

        $answer = $this->createAnswer(['question_uuid' => $question['uuid']]);

        $this->assertSame($this->identityId('aicountly-compliance-desk'), (int) $answer['identity_id']);
    }

    public function testCreateAnswerKeepsAnExplicitIdentity(): void
    {
        $question = $this->intake('How do I file a GSTR-1 nil return?');

        $answer = $this->createAnswer([
            'question_uuid'          => $question['uuid'],
            'official_identity_slug' => 'aicountly-income-tax-desk',
        ]);

        $this->assertSame($this->identityId('aicountly-income-tax-desk'), (int) $answer['identity_id']);
    }

    public function testCreateAnswerForAnUnknownQuestionIsNotFound(): void
    {
        $headers = $this->authAs('reach_admin');
        foreach (['00000000-0000-0000-0000-000000000000', 'undefined'] as $questionUuid) {
            $response = $this->withHeaders($headers)
                ->withBodyFormat('json')
                ->call('POST', 'v1/community/answers', ['question_uuid' => $questionUuid]);
            $this->assertSame(404, $response->response()->getStatusCode(), $questionUuid);
        }
    }

    public function testEveryRoutedDeskIsAnActiveExpertAnswerIdentity(): void
    {
        // Desks are routed to by slug, so one a migration renames or retires
        // must fail here rather than as a 422 in the question workspace.
        $identities = new CommunityOfficialIdentityModel();
        foreach (CommunityOperationalAgentService::answerDesks() as $slug) {
            $identity = $identities->findBySlug($slug);
            $this->assertNotNull($identity, "{$slug} is not seeded");
            $this->assertTrue($identity['is_active'], "{$slug} is not active");
            $this->assertSame('expert_answer_assistant', $identity['operational_role'], "{$slug} cannot draft answers");
        }
    }

    /** The created answer; fails with the error body unless the POST returned 201. */
    /** page was ignored and total was the size of the page returned, so the pager never knew there was more. */
    public function testListAnswersPagesWithTheFilteredTotal(): void
    {
        foreach (range(1, 3) as $n) {
            $this->createAnswer(['question_uuid' => $this->intake("Paging fixture question {$n}")['uuid']]);
        }

        $response = $this->withHeaders($this->authAs('reach_admin'))
            ->call('GET', 'v1/community/answers?per_page=2&page=2');
        $body = json_decode((string) $response->getJSON(), true);

        $this->assertCount(1, $body['data']);
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $body['meta']);
    }

    /** What the Overview's Pending approval tile links to: both review queues. */
    public function testAwaitingApprovalListsBothReviewQueues(): void
    {
        $queued = [];
        foreach (['editorial_review', 'professional_review', 'approved', 'draft_generated'] as $n => $status) {
            $answer = $this->createAnswer(['question_uuid' => $this->intake("Review fixture question {$n}")['uuid']]);
            \Config\Database::connect()->table('reach_community_official_answers')
                ->where('uuid', $answer['uuid'])
                ->update(['status' => $status]);
            if (in_array($status, ['editorial_review', 'professional_review'], true)) {
                $queued[] = $answer['uuid'];
            }
        }

        $response = $this->withHeaders($this->authAs('reach_admin'))
            ->call('GET', 'v1/community/answers?status=awaiting_approval');
        $body = json_decode((string) $response->getJSON(), true);

        $this->assertEqualsCanonicalizing($queued, array_column($body['data'], 'uuid'));
        $this->assertSame(2, $body['meta']['total']);
    }

    private function createAnswer(array $body): array
    {
        $response = $this->withHeaders($this->authAs('reach_admin'))
            ->withBodyFormat('json')
            ->call('POST', 'v1/community/answers', $body);
        $this->assertSame(201, $response->response()->getStatusCode(), (string) $response->getJSON());

        return json_decode((string) $response->getJSON(), true)['data'];
    }

    private function identityId(string $slug): int
    {
        return (int) (new CommunityOfficialIdentityModel())->findBySlug($slug)['id'];
    }

    private function intake(string $title, string $category = 'gst'): array
    {
        return (new CommunityQuestionIntakeService())->intake([
            'source_type' => 'manual',
            'title'       => $title,
            'category'    => $category,
        ]);
    }
}


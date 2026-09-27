<?php

namespace Tests\Feature\Community;

use App\Libraries\Community\CommunityQuestionIntakeService;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Feature tests for community question inbox API.
 */
final class QuestionInboxApiTest extends ApiTestCase
{
    public function testListQuestionsRequiresAuth(): void
    {
        $response = $this->call('GET', 'v1/community/questions');
        $this->assertSame(401, $response->response()->getStatusCode());
    }

    public function testListQuestionsReturnsPaginatedData(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
    }

    public function testListQuestionsStatsEndpointReturns200(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions/stats');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertArrayHasKey('data', $body);
    }

    public function testGetNonExistentQuestionReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions/00000000-0000-0000-0000-000000000000');
        $this->assertSame(404, $response->response()->getStatusCode());
    }

    public function testGetQuestionWithAMalformedIdReturns404(): void
    {
        // The inbox's Open link once built /community/questions/undefined,
        // and Postgres rejecting it on the uuid column surfaced as a 500.
        $headers = $this->authAs('reach_admin');

        foreach (['undefined', 'not-a-uuid', '42'] as $id) {
            $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions/' . $id);
            $this->assertSame(404, $response->response()->getStatusCode(), "GET {$id}");
        }
    }

    public function testStatusChangeWithAMalformedIdReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->call('PUT', 'v1/community/questions/undefined/status', ['status' => 'triaged']);
        $this->assertSame(404, $response->response()->getStatusCode());
    }

    public function testListQuestionsFilterByStatus(): void
    {
        $question = $this->intake('How do I file a GSTR-1 nil return?');
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions?status=intake');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertContains($question['uuid'], array_column($body['data'], 'uuid'));
        foreach ($body['data'] as $q) {
            $this->assertSame('intake', $q['status']);
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

    public function testListRowCarriesTheFieldsTheInboxReads(): void
    {
        $spaceId  = $this->space('gst-help');
        $question = $this->intake('How do I claim GST input tax credit on capital goods?', $spaceId);

        $row = $this->listedRow($question['uuid']);

        // The inbox links by uuid, shows intake_timestamp as Received, and
        // reads risk from the question's classification — the heuristic
        // classifier rates a GST question high.
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row['uuid']);
        $this->assertNotEmpty($row['intake_timestamp']);
        $this->assertSame('high', $row['risk_classification']);
        $this->assertSame('gst-help', $row['space_slug']);
        $this->assertFalse($row['personal_data_detected']);
    }

    public function testListRiskIsTheNewestClassificationAndTheRowIsNotRepeated(): void
    {
        $question = $this->intake('How do I claim GST input tax credit on capital goods?');

        Database::connect()->table('reach_community_question_classifications')->insert([
            'question_id'         => $question['id'],
            'risk_classification' => 'critical',
            'classified_by'       => 'human',
        ]);

        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions?per_page=100');
        $body     = json_decode((string) $response->getJSON(), true);
        $rows     = array_values(array_filter($body['data'], static fn (array $r) => $r['uuid'] === $question['uuid']));

        $this->assertCount(1, $rows);
        $this->assertSame('critical', $rows[0]['risk_classification']);
        $this->assertSame(
            Database::connect()->table('reach_community_questions')->countAllResults(),
            $body['meta']['total']
        );
    }

    public function testListRiskIsNullForAnUnclassifiedQuestion(): void
    {
        // Only manual intake classifies inline; imports wait for the agents.
        $question = $this->intake('Is an e-way bill needed below 50,000 rupees?', null, 'import');

        $this->assertNull($this->listedRow($question['uuid'])['risk_classification']);
    }

    public function testShowReturnsTheQuestionAsTheInboxListsIt(): void
    {
        $spaceId  = $this->space('gst-help');
        $question = $this->intake('How do I claim GST input tax credit on capital goods?', $spaceId);

        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions/' . $question['uuid']);
        $this->assertSame(200, $response->response()->getStatusCode());
        $data = json_decode((string) $response->getJSON(), true)['data'];

        $this->assertSame($question['uuid'], $data['uuid']);
        $this->assertSame('manual', $data['source_type']);
        $this->assertSame('high', $data['risk_classification']);
        $this->assertSame('gst-help', $data['space_slug']);
        $this->assertNotEmpty($data['intake_timestamp']);
        $this->assertFalse($data['personal_data_detected']);
        $this->assertSame(array_keys($this->listedRow($question['uuid'])), array_keys($data));
    }

    public function testStatusChangeRecordsTheNoteAndTheActor(): void
    {
        $question = $this->intake('How do I file a GSTR-1 nil return?');
        $headers  = $this->authAs('reach_admin');

        $response = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->call('PUT', 'v1/community/questions/' . $question['uuid'] . '/status', [
                'status' => 'triaged',
                'note'   => 'Checked against the GST desk backlog.',
            ]);
        $this->assertSame(200, $response->response()->getStatusCode());

        $db = Database::connect();
        $this->assertSame(
            'triaged',
            $db->table('reach_community_questions')->where('id', $question['id'])->get()->getRowArray()['status']
        );

        $audit = $db->table('reach_audit_logs')
            ->where('action', 'community.question.status_changed')
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
        $this->assertNotNull($audit);
        $this->assertNotNull($audit['user_id']);
        $metadata = json_decode((string) $audit['metadata'], true);
        $this->assertSame($question['uuid'], $metadata['uuid']);
        $this->assertSame('Checked against the GST desk backlog.', $metadata['note']);
    }

    private function intake(string $title, ?int $spaceId = null, string $sourceType = 'manual'): array
    {
        return (new CommunityQuestionIntakeService())->intake([
            'source_type' => $sourceType,
            'title'       => $title,
            'category'    => 'gst',
            'space_id'    => $spaceId,
        ]);
    }

    private function space(string $slug): int
    {
        $db = Database::connect();
        $db->table('reach_community_spaces')->insert(['slug' => $slug, 'title' => 'GST help']);

        return (int) $db->insertID();
    }

    private function listedRow(string $uuid): array
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/community/questions?per_page=100');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);

        foreach ($body['data'] as $row) {
            if ($row['uuid'] === $uuid) {
                return $row;
            }
        }

        $this->fail("Question {$uuid} is missing from the inbox list.");
    }
}

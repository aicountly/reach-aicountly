<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Feature tests for community question / official answer deletion.
 */
final class CommunityDeleteApiTest extends ApiTestCase
{
    public function testDeleteAnswerRemovesItWithItsChildRows(): void
    {
        $headers = $this->authAs('reach_admin');
        $seed    = $this->seedQuestionWithAnswer('draft_generated');

        $response = $this->withHeaders($headers)
            ->call('DELETE', 'v1/community/answers/' . $seed['answer_uuid']);

        $this->assertSame(200, $response->response()->getStatusCode());

        $db = db_connect();
        $this->assertSame(0, $db->table('reach_community_official_answers')->where('id', $seed['answer_id'])->countAllResults());
        $this->assertSame(0, $db->table('reach_community_answer_versions')->where('answer_id', $seed['answer_id'])->countAllResults());
        $this->assertSame(0, $db->table('reach_community_deployments')->where('answer_id', $seed['answer_id'])->countAllResults());

        // The question itself survives an answer delete.
        $this->assertSame(1, $db->table('reach_community_questions')->where('id', $seed['question_id'])->countAllResults());
    }

    public function testDeletePublishedAnswerIsRejected(): void
    {
        $headers = $this->authAs('reach_admin');
        $seed    = $this->seedQuestionWithAnswer('published');

        $response = $this->withHeaders($headers)
            ->call('DELETE', 'v1/community/answers/' . $seed['answer_uuid']);

        $this->assertSame(422, $response->response()->getStatusCode());
        $this->assertSame(
            1,
            db_connect()->table('reach_community_official_answers')->where('id', $seed['answer_id'])->countAllResults(),
        );
    }

    public function testDeleteUnknownAnswerReturns404(): void
    {
        $headers  = $this->authAs('reach_admin');
        $response = $this->withHeaders($headers)
            ->call('DELETE', 'v1/community/answers/00000000-dead-beef-0000-000000000000');

        $this->assertSame(404, $response->response()->getStatusCode());
    }

    public function testDeleteQuestionWithAnswersRequiresConfirmation(): void
    {
        $headers = $this->authAs('reach_admin');
        $seed    = $this->seedQuestionWithAnswer('draft_generated');

        $response = $this->withHeaders($headers)
            ->call('DELETE', 'v1/community/questions/' . $seed['question_uuid']);

        $this->assertSame(422, $response->response()->getStatusCode());
        $this->assertSame(
            1,
            db_connect()->table('reach_community_questions')->where('id', $seed['question_id'])->countAllResults(),
        );
    }

    public function testDeleteQuestionWithAnswersRemovesBoth(): void
    {
        $headers = $this->authAs('reach_admin');
        $seed    = $this->seedQuestionWithAnswer('draft_generated');

        $response = $this->withHeaders($headers)
            ->withBodyFormat('json')
            ->call(
                'DELETE',
                'v1/community/questions/' . $seed['question_uuid'],
                ['with_answers' => true],
            );

        $this->assertSame(200, $response->response()->getStatusCode());

        $db = db_connect();
        $this->assertSame(0, $db->table('reach_community_questions')->where('id', $seed['question_id'])->countAllResults());
        $this->assertSame(0, $db->table('reach_community_official_answers')->where('id', $seed['answer_id'])->countAllResults());
        $this->assertSame(0, $db->table('reach_community_answer_versions')->where('answer_id', $seed['answer_id'])->countAllResults());
    }

    public function testDeleteQuestionRequiresModeratePermission(): void
    {
        $headers = $this->authAs('blog_author');
        $seed    = $this->seedQuestionWithAnswer('draft_generated');

        $response = $this->withHeaders($headers)
            ->call('DELETE', 'v1/community/questions/' . $seed['question_uuid']);

        $this->assertContains($response->response()->getStatusCode(), [401, 403]);
        $this->assertSame(
            1,
            db_connect()->table('reach_community_questions')->where('id', $seed['question_id'])->countAllResults(),
        );
    }

    /**
     * Seeds a question with one official answer, a version, an approval record
     * and a deployment row — i.e. every child table the delete has to clear.
     *
     * @return array{question_id:int, question_uuid:string, answer_id:int, answer_uuid:string}
     */
    private function seedQuestionWithAnswer(string $answerStatus): array
    {
        $db = db_connect();

        $identityId = (int) $db->table('reach_community_official_identities')
            ->where('slug', 'aicountly-official')
            ->get()->getRowArray()['id'];

        $db->table('reach_community_questions')->insert([
            'title'  => 'Which ITR form applies to a partnership firm?',
            'body'   => 'Seeded by CommunityDeleteApiTest.',
            'status' => 'triaged',
        ]);
        $question = $db->table('reach_community_questions')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $publicationStatus = $answerStatus === 'published' ? 'published' : 'unpublished';
        $db->table('reach_community_official_answers')->insert([
            'question_id'        => $question['id'],
            'identity_id'        => $identityId,
            'current_version'    => 1,
            'status'             => $answerStatus,
            'publication_status' => $publicationStatus,
        ]);
        $answer = $db->table('reach_community_official_answers')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        $db->table('reach_community_answer_versions')->insert([
            'answer_id'      => $answer['id'],
            'version_number' => 1,
            'content'        => 'Seeded answer content.',
            'checksum'       => str_repeat('a', 64),
        ]);

        $db->table('reach_community_deployments')->insert([
            'answer_id'             => $answer['id'],
            'answer_version_number' => 1,
            'version_checksum'      => str_repeat('a', 64),
            'operation'             => 'publish',
            'idempotency_key'       => sprintf(
                '%08x-%04x-4%03x-%04x-%012x',
                random_int(0, 0xffffffff),
                random_int(0, 0xffff),
                random_int(0, 0xfff),
                random_int(0x8000, 0xbfff),
                random_int(0, 0xffffffffffff),
            ),
            'status'                => 'succeeded',
        ]);

        return [
            'question_id'   => (int) $question['id'],
            'question_uuid' => (string) $question['uuid'],
            'answer_id'     => (int) $answer['id'],
            'answer_uuid'   => (string) $answer['uuid'],
        ];
    }
}

<?php

namespace Tests\Feature\Community;

use Tests\Support\ApiTestCase;

/**
 * Permanent deletion of community Q&A:
 *   DELETE /v1/community/questions/:uuid
 *   DELETE /v1/community/answers/:uuid
 *
 * Withdrawal keeps the record; these routes remove it along with the versions,
 * approvals and deployment rows that the schema pins with ON DELETE RESTRICT.
 */
final class CommunityPurgeApiTest extends ApiTestCase
{
    /** @return array{0: int, 1: string} question id and uuid */
    private function seedQuestion(string $title = 'Junk intake'): array
    {
        $db = \Config\Database::connect();
        $db->table('reach_community_questions')->insert([
            'title'  => $title,
            'body'   => 'Body text.',
            'status' => 'intake',
        ]);
        $id  = (int) $db->insertID();
        $row = $db->table('reach_community_questions')->where('id', $id)->get()->getRowArray();

        return [$id, (string) $row['uuid']];
    }

    /** @return array{0: int, 1: string} answer id and uuid */
    private function seedAnswer(int $questionId, string $status = 'draft_generated'): array
    {
        $db       = \Config\Database::connect();
        $identity = $db->table('reach_community_official_identities')
            ->where('slug', 'aicountly-official')->get()->getRowArray();

        $db->table('reach_community_official_answers')->insert([
            'question_id'        => $questionId,
            'identity_id'        => (int) $identity['id'],
            'status'             => $status,
            'publication_status' => 'unpublished',
        ]);
        $id  = (int) $db->insertID();
        $row = $db->table('reach_community_official_answers')->where('id', $id)->get()->getRowArray();

        $db->table('reach_community_answer_versions')->insert([
            'answer_id'      => $id,
            'version_number' => 1,
            'content'        => 'Draft answer content.',
            'checksum'       => str_repeat('a', 64),
        ]);

        return [$id, (string) $row['uuid']];
    }

    public function testDeleteAnswerRemovesItAndItsVersions(): void
    {
        $headers          = $this->authAs('super_admin');
        [$questionId]     = $this->seedQuestion('Question keeping its answer');
        [$answerId, $uuid] = $this->seedAnswer($questionId);
        $db               = \Config\Database::connect();

        $res = $this->withHeaders($headers)->call('DELETE', 'v1/community/answers/' . $uuid);
        $this->assertSame(200, $res->response()->getStatusCode());

        $this->assertSame(0, $db->table('reach_community_official_answers')->where('id', $answerId)->countAllResults());
        $this->assertSame(0, $db->table('reach_community_answer_versions')->where('answer_id', $answerId)->countAllResults());
        // Deleting an answer must not take the question with it.
        $this->assertSame(1, $db->table('reach_community_questions')->where('id', $questionId)->countAllResults());
    }

    public function testDeleteQuestionAlsoDeletesItsAnswers(): void
    {
        $headers             = $this->authAs('super_admin');
        [$questionId, $uuid] = $this->seedQuestion();
        [$answerId]          = $this->seedAnswer($questionId);
        $db                  = \Config\Database::connect();

        $res = $this->withHeaders($headers)->call('DELETE', 'v1/community/questions/' . $uuid);
        $this->assertSame(200, $res->response()->getStatusCode());

        $body = json_decode((string) $res->getJSON(), true);
        $this->assertSame(1, $body['data']['answers_deleted']);

        $this->assertSame(0, $db->table('reach_community_questions')->where('id', $questionId)->countAllResults());
        $this->assertSame(0, $db->table('reach_community_official_answers')->where('id', $answerId)->countAllResults());
        $this->assertSame(0, $db->table('reach_community_answer_versions')->where('answer_id', $answerId)->countAllResults());
    }

    public function testDeleteQuestionIsDeniedWithoutThePermission(): void
    {
        [$questionId, $uuid] = $this->seedQuestion('Not yours to delete');

        $headers = $this->authAs('blog_author');
        $res     = $this->withHeaders($headers)->call('DELETE', 'v1/community/questions/' . $uuid);

        $this->assertContains($res->response()->getStatusCode(), [401, 403]);
        $this->assertSame(
            1,
            \Config\Database::connect()->table('reach_community_questions')->where('id', $questionId)->countAllResults()
        );
    }

    public function testDeleteAnswerIsDeniedWithoutThePermission(): void
    {
        [$questionId]      = $this->seedQuestion('Question for a protected answer');
        [$answerId, $uuid] = $this->seedAnswer($questionId);

        $headers = $this->authAs('blog_author');
        $res     = $this->withHeaders($headers)->call('DELETE', 'v1/community/answers/' . $uuid);

        $this->assertContains($res->response()->getStatusCode(), [401, 403]);
        $this->assertSame(
            1,
            \Config\Database::connect()->table('reach_community_official_answers')->where('id', $answerId)->countAllResults()
        );
    }

    public function testDeleteUnknownQuestionReturns404(): void
    {
        $headers = $this->authAs('super_admin');
        $res     = $this->withHeaders($headers)
            ->call('DELETE', 'v1/community/questions/00000000-dead-beef-0000-000000000000');

        $this->assertSame(404, $res->response()->getStatusCode());
    }

    public function testDeleteUnknownAnswerReturns404(): void
    {
        $headers = $this->authAs('super_admin');
        $res     = $this->withHeaders($headers)
            ->call('DELETE', 'v1/community/answers/00000000-dead-beef-0000-000000000000');

        $this->assertSame(404, $res->response()->getStatusCode());
    }
}

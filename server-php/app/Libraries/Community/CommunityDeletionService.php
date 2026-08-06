<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use RuntimeException;

/**
 * Permanent removal of community questions and official answers.
 *
 * The Phase 5 schema wires most child rows with ON DELETE RESTRICT, so the
 * dependent rows have to be cleared explicitly and in order. Everything runs
 * inside one transaction: either the whole aggregate goes or nothing does.
 *
 * Live public content is never removed silently — a published answer must be
 * unpublished or withdrawn first, and a question keeps its answers until they
 * are deleted (or they are removed with it when the caller opts in).
 */
class CommunityDeletionService
{
    /** Child tables keyed by the answer_id column, deleted before the answer. */
    private const ANSWER_CHILD_TABLES = [
        'reach_community_answer_verifications',
        'reach_community_deployments',
        'reach_community_answer_approvals',
        'reach_community_answer_versions',
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger()
    ) {}

    /**
     * Delete a single official answer and every row that depends on it.
     *
     * @param array $answer Answer row (must contain id, uuid, status).
     */
    public function deleteAnswer(array $answer, ?int $actorId = null, string $reason = ''): void
    {
        $this->assertAnswerRemovable($answer);

        $db = db_connect();
        $db->transException(true)->transStart();

        try {
            $this->purgeAnswer($db, (int) $answer['id']);
            $db->transComplete();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw new RuntimeException('Failed to delete official answer: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log(
            $actorId,
            AuditLogger::COMMUNITY_ANSWER_DELETED,
            'community_answer',
            (int) $answer['id'],
            $answer,
            null,
            ['reason' => $reason, 'answer_uuid' => $answer['uuid'] ?? null],
        );
    }

    /**
     * Delete a question. Answers block the delete unless $withAnswers is set,
     * in which case they are removed with the question.
     *
     * @param array $question Question row (must contain id, uuid).
     */
    public function deleteQuestion(array $question, ?int $actorId = null, string $reason = '', bool $withAnswers = false): void
    {
        $db         = db_connect();
        $questionId = (int) $question['id'];

        $answers = $db->table('reach_community_official_answers')
            ->where('question_id', $questionId)
            ->get()
            ->getResultArray();

        if ($answers !== [] && ! $withAnswers) {
            throw new RuntimeException(
                'This question has ' . count($answers) . ' official answer(s). Delete them first, or confirm deleting them with the question.'
            );
        }

        foreach ($answers as $answer) {
            $this->assertAnswerRemovable($answer);
        }

        $db->transException(true)->transStart();

        try {
            foreach ($answers as $answer) {
                $this->purgeAnswer($db, (int) $answer['id']);
            }

            // Clusters canonicalised on this question would otherwise RESTRICT.
            // Questions pointing at the cluster are detached by ON DELETE SET NULL.
            $db->table('reach_community_duplicate_clusters')
                ->where('canonical_question_id', $questionId)
                ->delete();

            $db->table('reach_community_questions')->where('id', $questionId)->delete();
            $db->transComplete();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw new RuntimeException('Failed to delete community question: ' . $e->getMessage(), 0, $e);
        }

        $this->audit->log(
            $actorId,
            AuditLogger::COMMUNITY_QUESTION_DELETED,
            'community_question',
            $questionId,
            $question,
            null,
            [
                'reason'           => $reason,
                'question_uuid'    => $question['uuid'] ?? null,
                'answers_deleted'  => count($answers),
            ],
        );
    }

    /** Published answers stay put until they are unpublished or withdrawn. */
    private function assertAnswerRemovable(array $answer): void
    {
        $status            = (string) ($answer['status'] ?? '');
        $publicationStatus = (string) ($answer['publication_status'] ?? '');

        if ($status === 'published' || $publicationStatus === 'published') {
            throw new RuntimeException('Unpublish or withdraw this answer before deleting it.');
        }
    }

    /**
     * Remove an answer's dependent rows and then the answer itself.
     * Source coverage and moderation findings cascade from answer versions.
     */
    private function purgeAnswer(\CodeIgniter\Database\BaseConnection $db, int $answerId): void
    {
        foreach (self::ANSWER_CHILD_TABLES as $table) {
            $db->table($table)->where('answer_id', $answerId)->delete();
        }

        $db->table('reach_community_official_answers')->where('id', $answerId)->delete();
    }
}

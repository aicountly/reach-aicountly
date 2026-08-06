<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;

/**
 * Permanent deletion of community questions and official answers.
 *
 * Withdrawal (OfficialAnswerWithdrawalService) is the normal way to take an
 * answer out of circulation: it keeps every version and approval for audit.
 * This service is the other end of the scale — the Reach panel's delete for
 * records that should never have existed (spam intake, mis-generated drafts).
 *
 * Anything still live on the public site is taken down first, so deleting from
 * Reach can never leave an orphaned answer published. `$force` skips that only
 * for operators who accept removing the public copy by hand.
 *
 * Every table below is deleted explicitly because the community schema uses
 * ON DELETE RESTRICT throughout — versions, approvals, deployments and
 * verifications all pin their answer, and answers pin their question.
 */
class CommunityPurgeService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct(
        private readonly OfficialAnswerRepository $answerRepo = new OfficialAnswerRepository(),
        private readonly CommunityQuestionRepository $questionRepo = new CommunityQuestionRepository(),
        private readonly ?OfficialAnswerPublishingService $publishing = null,
    ) {
        $this->db = \Config\Database::connect();
    }

    /**
     * Delete one official answer and its history.
     *
     * @return array{deleted: bool, unpublished: bool}
     * @throws \RuntimeException when the answer is missing, still live, or the delete fails
     */
    public function purgeAnswer(int $answerId, string $reason, ?int $actorId = null, bool $force = false): array
    {
        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Official answer #{$answerId} not found.");
        }

        $unpublished = $this->takeDownAnswer($answerId, $answer, $reason, $force, $actorId);

        $this->db->transStart();
        $this->deleteAnswerRows($answerId);
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('Could not delete the answer — the database rejected the change.');
        }

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_DELETED, [
            'answer_id'   => $answerId,
            'answer_uuid' => $answer['uuid'] ?? null,
            'question_id' => $answer['question_id'] ?? null,
            'reason'      => substr($reason, 0, 200),
            'forced'      => $force,
        ], $actorId);

        return ['deleted' => true, 'unpublished' => $unpublished];
    }

    /**
     * Delete one question along with every answer written for it.
     *
     * @return array{deleted: bool, answers_deleted: int}
     * @throws \RuntimeException when the question is missing, an answer is still live, or the delete fails
     */
    public function purgeQuestion(int $questionId, string $reason, ?int $actorId = null, bool $force = false): array
    {
        $question = $this->questionRepo->findById($questionId);
        if ($question === null) {
            throw new \RuntimeException("Community question #{$questionId} not found.");
        }

        $answers = $this->db->table('reach_community_official_answers')
            ->where('question_id', $questionId)
            ->get()->getResultArray();

        // Take every public copy down before touching a single row, so a
        // failure halfway through leaves the question intact and re-deletable.
        foreach ($answers as $answer) {
            $this->takeDownAnswer((int) $answer['id'], $answer, $reason, $force, $actorId);
        }

        $this->db->transStart();

        foreach ($answers as $answer) {
            $this->deleteAnswerRows((int) $answer['id']);
        }

        // canonical_question_id is RESTRICT; the cluster has no meaning once
        // its canonical question is gone, and members are unlinked by its own
        // ON DELETE SET NULL.
        $this->db->table('reach_community_duplicate_clusters')
            ->where('canonical_question_id', $questionId)
            ->delete();

        // Classifications, moderation findings and related links cascade.
        $this->db->table('reach_community_questions')->where('id', $questionId)->delete();

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('Could not delete the question — the database rejected the change.');
        }

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_DELETED, [
            'question_id'     => $questionId,
            'question_uuid'   => $question['uuid'] ?? null,
            'title'           => $question['title'] ?? null,
            'answers_deleted' => count($answers),
            'reason'          => substr($reason, 0, 200),
            'forced'          => $force,
        ], $actorId);

        return ['deleted' => true, 'answers_deleted' => count($answers)];
    }

    // -------------------------------------------------------------------------

    /**
     * @throws \RuntimeException when the public takedown fails and $force is false
     */
    private function takeDownAnswer(
        int $answerId,
        array $answer,
        string $reason,
        bool $force,
        ?int $actorId = null
    ): bool {
        $isLive = ($answer['publication_status'] ?? '') === 'published'
            || ($answer['status'] ?? '') === 'published';

        if (!$isLive) {
            return false;
        }

        try {
            $result = $this->publishingService()->unpublish(
                $answerId,
                $reason !== '' ? $reason : 'Deleted from Reach',
                $actorId
            );
            $ok = (bool) ($result['success'] ?? false);
        } catch (\Throwable $e) {
            $ok = false;
            log_message('error', 'CommunityPurgeService takedown failed: ' . $e->getMessage());
        }

        if (!$ok && !$force) {
            throw new \RuntimeException(
                'This answer is still published and the takedown failed. '
                . 'Unpublish or withdraw it first, or delete with force to remove it from Reach only.'
            );
        }

        return $ok;
    }

    /** Child rows first — every community FK to an answer is RESTRICT. */
    private function deleteAnswerRows(int $answerId): void
    {
        $this->db->table('reach_community_answer_verifications')->where('answer_id', $answerId)->delete();
        $this->db->table('reach_community_deployments')->where('answer_id', $answerId)->delete();
        $this->db->table('reach_community_answer_approvals')->where('answer_id', $answerId)->delete();
        // Moderation findings cascade off the versions.
        $this->db->table('reach_community_answer_versions')->where('answer_id', $answerId)->delete();
        $this->db->table('reach_community_official_answers')->where('id', $answerId)->delete();
    }

    private function publishingService(): OfficialAnswerPublishingService
    {
        return $this->publishing ?? new OfficialAnswerPublishingService();
    }
}

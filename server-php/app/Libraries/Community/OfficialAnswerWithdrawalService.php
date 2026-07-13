<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\AuditLogger;

/**
 * Withdrawal and restoration of published official answers.
 *
 * Withdrawal preserves all audit history and version records.
 * Content is never deleted — it is marked as withdrawn and removed from public rendering.
 */
class OfficialAnswerWithdrawalService
{
    public function __construct(
        private readonly OfficialAnswerRepository $answerRepo = new OfficialAnswerRepository()
    ) {}

    /**
     * Withdraw a published answer.
     */
    public function withdraw(int $answerId, string $reason, ?int $actorId = null): void
    {
        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('Withdrawal reason is required.');
        }

        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Answer #{$answerId} not found");
        }

        $fromStatus = CommunityAnswerStatus::from($answer['status'] ?? 'published');

        // Allow withdrawal from published or unpublished states
        if (!in_array($fromStatus, [
            CommunityAnswerStatus::Published,
            CommunityAnswerStatus::Unpublished,
            CommunityAnswerStatus::VerificationFailed,
        ], true)) {
            throw new \RuntimeException("Cannot withdraw an answer in status: {$fromStatus->value}");
        }

        $this->answerRepo->save([
            'id'              => $answerId,
            'withdrawal_state' => 'withdrawn',
            'publication_status' => 'withdrawn',
            'status'           => CommunityAnswerStatus::Withdrawn->value,
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_WITHDRAWN, [
            'answer_id' => $answerId,
            'reason'    => substr($reason, 0, 200),
        ], $actorId);
    }

    /**
     * Restore a withdrawn answer to published state.
     * The answer's previously approved version must still be valid.
     */
    public function restore(int $answerId, ?int $actorId = null): void
    {
        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Answer #{$answerId} not found");
        }

        if (($answer['status'] ?? '') !== CommunityAnswerStatus::Withdrawn->value) {
            throw new \RuntimeException("Only withdrawn answers can be restored. Current: {$answer['status']}");
        }

        $this->answerRepo->save([
            'id'               => $answerId,
            'withdrawal_state' => 'none',
            'status'           => CommunityAnswerStatus::Restoring->value,
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_RESTORED, [
            'answer_id' => $answerId,
        ], $actorId);
    }
}

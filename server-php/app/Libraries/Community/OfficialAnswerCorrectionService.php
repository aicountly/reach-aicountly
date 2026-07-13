<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\AuditLogger;

/**
 * Manages answer corrections.
 *
 * A correction creates a new version with creation_reason = 'correction',
 * invalidates the prior approval, and routes through the standard
 * editorial/approval workflow before republication.
 */
class OfficialAnswerCorrectionService
{
    public function __construct(
        private readonly OfficialAnswerRepository    $answerRepo  = new OfficialAnswerRepository(),
        private readonly OfficialAnswerVersionService $versions   = new OfficialAnswerVersionService()
    ) {}

    /**
     * Start a correction: create new version, invalidate approval.
     */
    public function startCorrection(
        int    $answerId,
        string $correctedContent,
        string $correctedExcerpt,
        string $correctionNote,
        array  $sources  = [],
        ?int   $actorId  = null
    ): array {
        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new \RuntimeException("Answer #{$answerId} not found");
        }

        if (empty(trim($correctionNote))) {
            throw new \InvalidArgumentException('A public correction note is required when creating a correction version.');
        }

        $version = $this->versions->createVersion(
            $answerId,
            $correctedContent,
            $correctedExcerpt,
            $sources,
            'correction',
            [],
            [],
            [],
            $actorId
        );

        // Store correction note and state on the answer
        $this->answerRepo->save([
            'id'               => $answerId,
            'correction_state' => 'pending',
            'correction_note'  => $correctionNote,
            'status'           => CommunityAnswerStatus::CorrectionRequired->value,
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_CORRECTION_STARTED, [
            'answer_id'       => $answerId,
            'version_number'  => $version['version_number'],
            'correction_note' => substr($correctionNote, 0, 200),
        ], $actorId);

        return $version;
    }

    /**
     * Mark the correction as published (called after successful re-publication).
     */
    public function markCorrected(int $answerId, ?int $actorId = null): void
    {
        $this->answerRepo->save([
            'id'               => $answerId,
            'correction_state' => 'corrected',
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_CORRECTED, [
            'answer_id' => $answerId,
        ], $actorId);
    }
}

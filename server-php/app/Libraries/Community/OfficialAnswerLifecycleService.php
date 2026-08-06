<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityQuestionStatus;
use App\Enums\CommunityRiskTier;
use App\Libraries\AuditLogger;
use App\Models\CommunityOfficialAnswerModel;
use App\Models\CommunityOfficialIdentityModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Canonical entry point for the official answer lifecycle.
 *
 * Reach exposes UUIDs on its HTTP surface and uses numeric primary keys
 * internally. This service is the single documented boundary where that
 * translation happens: it resolves a UUID once, then delegates to the numeric
 * ID-typed domain services. Controllers, jobs and CLI commands go through here
 * rather than calling the domain services with UUID strings.
 *
 * Lifecycle:
 *   createDraft -> requestGeneration -> validate -> moderate -> submitForReview
 *   -> approve -> schedule -> publish -> correct -> withdraw -> restore -> archive
 */
class OfficialAnswerLifecycleService
{
    public function __construct(
        private readonly OfficialAnswerRepository        $answerRepo    = new OfficialAnswerRepository(),
        private readonly CommunityQuestionRepository     $questionRepo  = new CommunityQuestionRepository(),
        private readonly OfficialAnswerVersionService    $versions      = new OfficialAnswerVersionService(),
        private readonly OfficialAnswerGenerationService $generation    = new OfficialAnswerGenerationService(),
        private readonly OfficialAnswerValidationService $validation    = new OfficialAnswerValidationService(),
        private readonly OfficialAnswerModerationService $moderation    = new OfficialAnswerModerationService(),
        private readonly OfficialAnswerApprovalService   $approval      = new OfficialAnswerApprovalService(),
        private readonly OfficialAnswerPublishingService $publishing    = new OfficialAnswerPublishingService(),
        private readonly OfficialAnswerWithdrawalService $withdrawal    = new OfficialAnswerWithdrawalService(),
        private readonly OfficialAnswerCorrectionService $correction    = new OfficialAnswerCorrectionService(),
        private readonly CommunityOfficialAnswerModel    $answerModel   = new CommunityOfficialAnswerModel(),
        private readonly CommunityOfficialIdentityModel  $identityModel = new CommunityOfficialIdentityModel(),
    ) {}

    // -------------------------------------------------------------------------
    // Identifier resolution
    // -------------------------------------------------------------------------

    /** Resolve a public answer UUID to its internal numeric primary key. */
    public function resolveAnswerId(string $answerUuid): int
    {
        $answer = $this->answerModel->findByUuid($answerUuid);
        if ($answer === null) {
            throw new RuntimeException("Official answer not found: {$answerUuid}");
        }
        return (int) $answer['id'];
    }

    /** Load the full answer aggregate (identity + question joined) by UUID. */
    public function requireAnswer(string $answerUuid): array
    {
        return $this->answerRepo->requireByUuid($answerUuid);
    }

    /**
     * The version an action targets when the caller did not name one.
     * Always the newest version, because older versions are superseded.
     */
    public function currentVersionNumber(int $answerId): int
    {
        $latest = $this->answerRepo->getLatestVersion($answerId);
        if ($latest === null) {
            throw new RuntimeException("Answer #{$answerId} has no versions yet.");
        }
        return (int) $latest['version_number'];
    }

    // -------------------------------------------------------------------------
    // Lifecycle: draft
    // -------------------------------------------------------------------------

    /**
     * Create an empty official answer record attached to a question.
     * No content is generated here — this only reserves the aggregate.
     */
    public function createDraft(string $questionUuid, string $identitySlug, ?int $actorId = null): array
    {
        $question = $this->questionRepo->requireByUuid($questionUuid);

        $identity = $this->identityModel->findBySlug($identitySlug);
        if ($identity === null) {
            throw new InvalidArgumentException("Unknown official identity: {$identitySlug}");
        }
        if (! ($identity['is_active'] ?? false)) {
            throw new InvalidArgumentException("Official identity is not active: {$identitySlug}");
        }

        $existing = $this->answerModel->findByQuestionId((int) $question['id']);
        if ($existing !== null) {
            throw new RuntimeException(
                "Question {$questionUuid} already has official answer {$existing['uuid']}."
            );
        }

        $riskTier = CommunityRiskTier::fromQuestion($question);

        $answerId = $this->answerRepo->save([
            'uuid'                => $this->generateUuid(),
            'question_id'         => (int) $question['id'],
            'identity_id'         => (int) $identity['id'],
            'current_version'     => 0,
            'publication_status'  => 'unpublished',
            'ai_assisted'         => false,
            'human_reviewed'      => false,
            'risk_classification' => $riskTier->toClassification()->value,
            'jurisdiction'        => $question['jurisdiction'] ?? null,
            'product'             => $question['product'] ?? null,
            'language'            => $question['language'] ?? 'en',
            'correction_state'    => 'none',
            'withdrawal_state'    => 'none',
            'status'              => CommunityAnswerStatus::DraftRequested->value,
        ]);

        $this->setRiskTier($answerId, $riskTier);
        $this->advanceQuestionToDraftRequested($question);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_DRAFT_CREATED, [
            'answer_id'     => $answerId,
            'question_id'   => (int) $question['id'],
            'identity_slug' => $identitySlug,
            'risk_tier'     => $riskTier->value,
        ], $actorId);

        return $this->answerRepo->findById($answerId) ?? [];
    }

    /** Request AI generation of a draft for an existing answer record. */
    public function requestGeneration(string $answerUuid, string $answerType = 'detailed', ?int $actorId = null): array
    {
        return $this->generation->requestGeneration($this->resolveAnswerId($answerUuid), $answerType, $actorId);
    }

    /**
     * Persist a human edit as a new immutable version.
     * Any existing approval is invalidated by the version service.
     */
    public function saveHumanEdit(
        string $answerUuid,
        string $content,
        string $excerpt,
        array  $sources = [],
        ?int   $actorId = null
    ): array {
        if (trim($content) === '') {
            throw new InvalidArgumentException('Answer content cannot be empty.');
        }

        return $this->versions->createVersion(
            $this->resolveAnswerId($answerUuid),
            $content,
            $excerpt,
            $sources,
            'edit',
            [],
            [],
            [],
            $actorId
        );
    }

    // -------------------------------------------------------------------------
    // Lifecycle: quality gates
    // -------------------------------------------------------------------------

    public function validate(string $answerUuid, ?int $versionNumber = null): array
    {
        $answerId = $this->resolveAnswerId($answerUuid);

        $result = $this->validation->validateVersion(
            $answerId,
            $versionNumber ?? $this->currentVersionNumber($answerId)
        );

        // The validation service only records findings; the status move lives
        // here so a passing re-validation lifts an answer out of
        // validation_failed / changes_requested and a failing one demotes a
        // draft — otherwise the Validate action never changes anything.
        $answer = $this->answerRepo->findById($answerId);
        if ($answer !== null) {
            $status = CommunityAnswerStatus::from($answer['status']);
            $target = null;
            if ($result['passed'] && in_array($status, [CommunityAnswerStatus::ValidationFailed, CommunityAnswerStatus::ChangesRequested], true)) {
                $target = CommunityAnswerStatus::DraftGenerated;
            } elseif (! $result['passed'] && $status === CommunityAnswerStatus::DraftGenerated) {
                $target = CommunityAnswerStatus::ValidationFailed;
            }
            if ($target !== null && $status->canTransitionTo($target)) {
                $this->answerRepo->transitionStatus($answerId, $status, $target);
                $result['status'] = $target->value;
            } else {
                $result['status'] = $status->value;
            }
        }

        return $result;
    }

    public function moderate(string $answerUuid, ?int $versionNumber = null, ?int $actorId = null): array
    {
        $answerId = $this->resolveAnswerId($answerUuid);

        return $this->moderation->moderate(
            $answerId,
            $versionNumber ?? $this->currentVersionNumber($answerId),
            $actorId
        );
    }

    /**
     * Move a generated draft into the correct human review queue.
     * Risk tier decides whether editorial review alone is sufficient.
     */
    public function submitForReview(string $answerUuid, ?int $actorId = null): array
    {
        $answerId = $this->resolveAnswerId($answerUuid);
        $answer   = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new RuntimeException("Answer #{$answerId} not found");
        }

        $this->answerRepo->transitionStatus(
            $answerId,
            CommunityAnswerStatus::from($answer['status']),
            CommunityAnswerStatus::EditorialReview
        );

        $tier   = $this->riskTierOf($answer);
        $target = CommunityAnswerStatus::EditorialReview;

        if ($tier->requiresProfessionalApproval()) {
            $this->answerRepo->transitionStatus(
                $answerId,
                CommunityAnswerStatus::EditorialReview,
                CommunityAnswerStatus::ProfessionalReview
            );
            $target = CommunityAnswerStatus::ProfessionalReview;
        }

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_REVIEW_REQUESTED, [
            'answer_id' => $answerId,
            'risk_tier' => $tier->value,
            'queue'     => $target->value,
        ], $actorId);

        return ['answer_id' => $answerId, 'status' => $target->value, 'risk_tier' => $tier->value];
    }

    // -------------------------------------------------------------------------
    // Lifecycle: approval
    // -------------------------------------------------------------------------

    public function approve(
        string $answerUuid,
        int    $approverId,
        ?int   $versionNumber = null,
        string $approvalType  = 'standard',
        string $reason        = ''
    ): array {
        $answerId = $this->resolveAnswerId($answerUuid);
        $answer   = $this->answerRepo->findById($answerId);
        $tier     = $this->riskTierOf($answer ?? []);

        if ($tier === CommunityRiskTier::Prohibited) {
            throw new RuntimeException(
                'Risk tier 4 (prohibited) content cannot be approved. It must be routed to moderation.'
            );
        }
        if ($tier->requiresProfessionalApproval() && $approvalType === 'standard') {
            throw new RuntimeException(
                "Risk tier {$tier->value} requires professional or compliance approval, not standard approval."
            );
        }

        return $this->approval->approve(
            $answerId,
            $versionNumber ?? $this->currentVersionNumber($answerId),
            $approverId,
            $approvalType,
            $reason
        );
    }

    public function reject(string $answerUuid, int $reviewerId, string $reason, ?int $versionNumber = null): void
    {
        $answerId = $this->resolveAnswerId($answerUuid);

        $this->approval->reject(
            $answerId,
            $versionNumber ?? $this->currentVersionNumber($answerId),
            $reviewerId,
            $reason
        );
    }

    public function requestChanges(string $answerUuid, int $reviewerId, string $reason): void
    {
        $this->approval->requestChanges($this->resolveAnswerId($answerUuid), $reviewerId, $reason);
    }

    // -------------------------------------------------------------------------
    // Lifecycle: scheduling and publication
    // -------------------------------------------------------------------------

    /**
     * Schedule an approved answer for publication at a future time.
     * The dispatcher publishes it once the time arrives and the window allows.
     */
    public function schedule(string $answerUuid, string $scheduledAt, ?int $actorId = null): array
    {
        $answerId  = $this->resolveAnswerId($answerUuid);
        $answer    = $this->answerRepo->findById($answerId);
        $timestamp = strtotime($scheduledAt);

        if ($answer === null) {
            throw new RuntimeException("Answer #{$answerId} not found");
        }
        if ($timestamp === false) {
            throw new InvalidArgumentException("Invalid scheduled_at value: {$scheduledAt}");
        }

        $this->answerRepo->transitionStatus(
            $answerId,
            CommunityAnswerStatus::from($answer['status']),
            CommunityAnswerStatus::Scheduled
        );

        $this->answerModel->db->table('reach_community_official_answers')
            ->where('id', $answerId)
            ->update(['scheduled_at' => date('Y-m-d H:i:s', $timestamp)]);

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_SCHEDULED, [
            'answer_id'    => $answerId,
            'scheduled_at' => date('c', $timestamp),
        ], $actorId);

        return ['answer_id' => $answerId, 'scheduled_at' => date('c', $timestamp)];
    }

    public function publish(string $answerUuid, ?int $actorId = null): array
    {
        return $this->publishing->publish($this->resolveAnswerId($answerUuid), $actorId);
    }

    public function unpublish(string $answerUuid, string $reason, ?int $actorId = null): array
    {
        return $this->publishing->unpublish($this->resolveAnswerId($answerUuid), $reason, $actorId);
    }

    // -------------------------------------------------------------------------
    // Lifecycle: post-publication
    // -------------------------------------------------------------------------

    public function correct(
        string $answerUuid,
        string $content,
        string $excerpt,
        string $correctionNote,
        array  $sources = [],
        ?int   $actorId = null
    ): array {
        return $this->correction->startCorrection(
            $this->resolveAnswerId($answerUuid),
            $content,
            $excerpt,
            $correctionNote,
            $sources,
            $actorId
        );
    }

    public function withdraw(string $answerUuid, string $reason, ?int $actorId = null): void
    {
        $this->withdrawal->withdraw($this->resolveAnswerId($answerUuid), $reason, $actorId);
    }

    /** Move withdrawn -> restoring locally, then re-publish to the public site. */
    public function restore(string $answerUuid, ?int $actorId = null): array
    {
        $answerId = $this->resolveAnswerId($answerUuid);

        $this->withdrawal->restore($answerId, $actorId);

        return $this->publishing->restore($answerId, $actorId);
    }

    /**
     * Permanent deletion. Archiving keeps the record; this removes it, and the
     * public copy with it.
     *
     * @return array{deleted: bool, unpublished: bool}
     */
    public function purge(string $answerUuid, string $reason, ?int $actorId = null, bool $force = false): array
    {
        return (new CommunityPurgeService())->purgeAnswer(
            $this->resolveAnswerId($answerUuid),
            $reason,
            $actorId,
            $force
        );
    }

    public function archive(string $answerUuid, ?int $actorId = null): void
    {
        $answerId = $this->resolveAnswerId($answerUuid);
        $answer   = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new RuntimeException("Answer #{$answerId} not found");
        }

        $this->answerRepo->transitionStatus(
            $answerId,
            CommunityAnswerStatus::from($answer['status']),
            CommunityAnswerStatus::Archived
        );

        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_ARCHIVED, ['answer_id' => $answerId], $actorId);
    }

    // -------------------------------------------------------------------------
    // Risk tier
    // -------------------------------------------------------------------------

    public function riskTierOf(array $answer): CommunityRiskTier
    {
        if (isset($answer['risk_tier']) && $answer['risk_tier'] !== null && $answer['risk_tier'] !== '') {
            return CommunityRiskTier::from((int) $answer['risk_tier']);
        }
        return CommunityRiskTier::fromClassification($answer['risk_classification'] ?? 'low');
    }

    /**
     * Raise or lower the risk tier. Lowering is an override and always requires
     * a reason plus an immutable audit entry; generation agents never call this.
     */
    public function setRiskTierWithOverride(
        string $answerUuid,
        CommunityRiskTier $tier,
        string $reason,
        int $actorId
    ): array {
        $answerId = $this->resolveAnswerId($answerUuid);
        $answer   = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            throw new RuntimeException("Answer #{$answerId} not found");
        }

        $current     = $this->riskTierOf($answer);
        $isDowngrade = $tier->value < $current->value;

        if ($isDowngrade && trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to lower an answer risk tier.');
        }

        $this->setRiskTier($answerId, $tier);
        $this->answerRepo->save([
            'id'                  => $answerId,
            'risk_classification' => $tier->toClassification()->value,
        ]);

        AuditLogger::record(
            $isDowngrade
                ? AuditLogger::COMMUNITY_ANSWER_RISK_DOWNGRADED
                : AuditLogger::COMMUNITY_ANSWER_RISK_CHANGED,
            [
                'answer_id' => $answerId,
                'from_tier' => $current->value,
                'to_tier'   => $tier->value,
                'reason'    => substr($reason, 0, 500),
            ],
            $actorId
        );

        return ['answer_id' => $answerId, 'risk_tier' => $tier->value];
    }

    private function setRiskTier(int $answerId, CommunityRiskTier $tier): void
    {
        $this->answerModel->db->table('reach_community_official_answers')
            ->where('id', $answerId)
            ->update(['risk_tier' => $tier->value]);
    }

    private function advanceQuestionToDraftRequested(array $question): void
    {
        $from = CommunityQuestionStatus::tryFrom($question['status'] ?? '');
        if ($from === null || $from === CommunityQuestionStatus::DraftRequested) {
            return;
        }

        // Intake questions must pass through triaged before a draft is requested.
        if ($from === CommunityQuestionStatus::Intake) {
            $this->questionRepo->transitionStatus((int) $question['id'], $from, CommunityQuestionStatus::Triaged);
            $from = CommunityQuestionStatus::Triaged;
        }

        if ($from->canTransitionTo(CommunityQuestionStatus::DraftRequested)) {
            $this->questionRepo->transitionStatus((int) $question['id'], $from, CommunityQuestionStatus::DraftRequested);
        }
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

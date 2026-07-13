<?php

namespace App\Enums;

enum CommunityAnswerStatus: string
{
    case Intake             = 'intake';
    case Triaged            = 'triaged';
    case DraftRequested     = 'draft_requested';
    case Generating         = 'generating';
    case DraftGenerated     = 'draft_generated';
    case ValidationFailed   = 'validation_failed';
    case ModerationRequired = 'moderation_required';
    case EditorialReview    = 'editorial_review';
    case ProfessionalReview = 'professional_review';
    case ChangesRequested   = 'changes_requested';
    case Approved           = 'approved';
    case Scheduled          = 'scheduled';
    case Publishing         = 'publishing';
    case Published          = 'published';
    case VerificationFailed = 'verification_failed';
    case CorrectionRequired = 'correction_required';
    case Unpublishing       = 'unpublishing';
    case Unpublished        = 'unpublished';
    case Restoring          = 'restoring';
    case Withdrawn          = 'withdrawn';
    case Archived           = 'archived';

    /** @return array<self> */
    public static function validTransitions(self $from): array
    {
        return match ($from) {
            self::Intake             => [self::DraftRequested, self::Archived],
            self::Triaged            => [self::DraftRequested, self::Archived],
            self::DraftRequested     => [self::Generating, self::Archived],
            self::Generating         => [self::DraftGenerated, self::ValidationFailed],
            self::DraftGenerated     => [self::ValidationFailed, self::ModerationRequired, self::EditorialReview],
            self::ValidationFailed   => [self::DraftGenerated, self::Archived],
            self::ModerationRequired => [self::DraftGenerated, self::Archived],
            self::EditorialReview    => [self::ProfessionalReview, self::ChangesRequested, self::Approved],
            self::ProfessionalReview => [self::ChangesRequested, self::Approved],
            self::ChangesRequested   => [self::DraftGenerated],
            self::Approved           => [self::Scheduled, self::Publishing],
            self::Scheduled          => [self::Publishing, self::Approved],
            self::Publishing         => [self::Published, self::VerificationFailed],
            self::Published          => [self::CorrectionRequired, self::Unpublishing, self::Withdrawn],
            self::VerificationFailed => [self::Publishing, self::Archived],
            self::CorrectionRequired => [self::DraftGenerated],
            self::Unpublishing       => [self::Unpublished],
            self::Unpublished        => [self::Restoring, self::Withdrawn],
            self::Restoring          => [self::Published],
            self::Withdrawn          => [self::Archived],
            self::Archived           => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitions($this), true);
    }

    public function isPublishable(): bool
    {
        return $this === self::Approved || $this === self::Scheduled;
    }

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }

    public function requiresReapprovalOnEdit(): bool
    {
        return in_array($this, [
            self::Approved,
            self::Scheduled,
            self::Published,
            self::CorrectionRequired,
        ], true);
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum VideoScriptWorkflowStatus: string
{
    case Draft             = 'draft';
    case InReview          = 'in_review';
    case Approved          = 'approved';
    case Rejected          = 'rejected';
    case ChangesRequested  = 'changes_requested';

    /** @return array<self> */
    public static function validTransitions(self $from): array
    {
        return match ($from) {
            self::Draft            => [self::InReview],
            self::InReview         => [self::Approved, self::Rejected, self::ChangesRequested],
            self::ChangesRequested => [self::Draft],
            self::Approved         => [],
            self::Rejected         => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitions($this), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }
}

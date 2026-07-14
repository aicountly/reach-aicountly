<?php

declare(strict_types=1);

namespace App\Enums;

enum VideoIdeaStatus: string
{
    case Draft     = 'draft';
    case Ready     = 'ready';
    case Accepted  = 'accepted';
    case Rejected  = 'rejected';
    case Archived  = 'archived';
    case Converted = 'converted';

    /** @return array<self> */
    public static function validTransitions(self $from): array
    {
        return match ($from) {
            self::Draft     => [self::Ready, self::Archived],
            self::Ready     => [self::Accepted, self::Rejected, self::Archived],
            self::Accepted  => [self::Converted, self::Archived],
            self::Rejected  => [self::Archived],
            self::Archived  => [],
            self::Converted => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitions($this), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Archived, self::Converted], true);
    }
}

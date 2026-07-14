<?php

declare(strict_types=1);

namespace App\Enums;

enum VideoRenderJobStatus: string
{
    case Queued      = 'queued';
    case Reserved    = 'reserved';
    case Rendering   = 'rendering';
    case Rendered    = 'rendered';
    case Failed      = 'failed';
    case Cancelled   = 'cancelled';
    case DeadLetter  = 'dead_letter';

    /** @return array<self> */
    public static function validTransitions(self $from): array
    {
        return match ($from) {
            self::Queued     => [self::Reserved, self::Cancelled],
            self::Reserved   => [self::Rendering, self::Failed, self::Cancelled],
            self::Rendering  => [self::Rendered, self::Failed, self::Cancelled],
            self::Failed     => [self::Queued, self::DeadLetter],
            self::Rendered   => [],
            self::Cancelled  => [],
            self::DeadLetter => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitions($this), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rendered, self::Cancelled, self::DeadLetter], true);
    }
}

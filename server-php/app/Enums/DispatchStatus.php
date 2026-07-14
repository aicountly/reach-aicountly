<?php

declare(strict_types=1);

namespace App\Enums;

enum DispatchStatus: string
{
    case Queued             = 'queued';
    case Dispatching        = 'dispatching';
    case Paused             = 'paused';
    case Cancelled          = 'cancelled';
    case PartiallyCompleted = 'partially_completed';
    case Completed          = 'completed';
    case Failed             = 'failed';
    case DeadLettered       = 'dead_lettered';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Cancelled,
            self::Completed,
            self::DeadLettered,
        ], true);
    }
}

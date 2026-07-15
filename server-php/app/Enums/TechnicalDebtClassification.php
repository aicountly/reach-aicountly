<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalDebtClassification: string
{
    case CriticalBlocker    = 'critical_blocker';
    case HighBlocker        = 'high_blocker';
    case ReleaseLimitation  = 'release_limitation';
    case AcceptedMedium     = 'accepted_medium';
    case AcceptedLow        = 'accepted_low';
    case Deferred           = 'deferred';
    case Superseded         = 'superseded';
    case OutOfScope         = 'out_of_scope';
}

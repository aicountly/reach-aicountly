<?php

declare(strict_types=1);

namespace App\Enums;

enum SuppressionReason: string
{
    case Unsubscribe    = 'unsubscribe';
    case Bounce         = 'bounce';
    case Complaint      = 'complaint';
    case Manual         = 'manual';
    case Legal          = 'legal';
    case OptOut         = 'opt_out';
    case InvalidAddress = 'invalid_address';

    public function isPermanent(): bool
    {
        return in_array($this, [self::Complaint, self::Legal, self::Bounce], true);
    }
}

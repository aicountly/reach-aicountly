<?php

declare(strict_types=1);

namespace App\Enums;

enum ConsentStatus: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function isEligible(): bool
    {
        return $this === self::Granted;
    }
}

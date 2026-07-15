<?php

declare(strict_types=1);

namespace App\Enums;

enum ReadinessFindingSeverity: string
{
    case Critical = 'critical';
    case High     = 'high';
    case Medium   = 'medium';
    case Low      = 'low';
    case Info     = 'info';

    public function isBlocker(): bool
    {
        return in_array($this, [self::Critical, self::High], true);
    }
}

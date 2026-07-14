<?php

declare(strict_types=1);

namespace App\Enums;

enum RecipientStatus: string
{
    case Queued       = 'queued';
    case Sending      = 'sending';
    case Accepted     = 'accepted';
    case Sent         = 'sent';
    case Delivered    = 'delivered';
    case Read         = 'read';
    case Failed       = 'failed';
    case Bounced      = 'bounced';
    case Complained   = 'complained';
    case Unsubscribed = 'unsubscribed';
    case Suppressed   = 'suppressed';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::Read,
            self::Failed,
            self::Bounced,
            self::Complained,
            self::Unsubscribed,
            self::Suppressed,
        ], true);
    }

    public function triggersSuppressionOnBounce(): bool
    {
        return $this === self::Bounced;
    }
}

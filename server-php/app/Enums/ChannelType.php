<?php

declare(strict_types=1);

namespace App\Enums;

enum ChannelType: string
{
    case Social   = 'social';
    case Email    = 'email';
    case WhatsApp = 'whatsapp';
    case Sms      = 'sms';

    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

/**
 * The provider is configured but sending is switched off (SMS_SKIP_SEND /
 * WHATSAPP_SKIP_SEND). Nothing left the building, so the attempt is recorded
 * as `skipped` — never `accepted` or `sent`.
 */
class SendSkippedException extends \RuntimeException
{
    public function __construct(string $message, int $code = 503, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

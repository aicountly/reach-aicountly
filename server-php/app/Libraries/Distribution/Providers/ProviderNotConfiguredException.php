<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

/**
 * No real provider can carry this message: the channel is not configured, the
 * adapter is a placeholder, or a mock was asked to send in production.
 *
 * Callers must record the message as failed (never sent/posted/delivered).
 */
class ProviderNotConfiguredException extends \RuntimeException
{
    public function __construct(string $message, int $code = 503, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

/**
 * A refused bot action, carrying the HTTP status the API answers with and a
 * machine-readable reason (`not_found`, `not_runnable`, `busy`, `cooldown`,
 * `not_configured`, `invalid`, `rate_limited`, …).
 */
final class BotActionException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly string $reason = 'invalid',
        public readonly ?int $retryAfter = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $httpStatus);
    }
}

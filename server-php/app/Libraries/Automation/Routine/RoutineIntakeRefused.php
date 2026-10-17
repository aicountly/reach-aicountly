<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

/**
 * A routine submission the API refuses before storing anything: automation is
 * switched off (403), today's quota is used (429) or the topic duplicates an
 * existing article (409). The message is safe to return to the routine and
 * tells it what to do next.
 */
final class RoutineIntakeRefused extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly int $httpStatus, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

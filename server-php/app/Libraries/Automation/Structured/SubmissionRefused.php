<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Structured;

/**
 * A routine submission Reach will not accept, with the HTTP status the
 * routine should see (403 automation off, 409 version or key conflict,
 * 422 invalid payload) and any field-level details.
 */
final class SubmissionRefused extends \RuntimeException
{
    /** @param mixed $details */
    public function __construct(string $message, public readonly int $httpStatus, public readonly mixed $details = null)
    {
        parent::__construct($message);
    }
}

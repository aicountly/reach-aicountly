<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * A failure that retrying cannot change (missing payload field, a record
 * that no longer exists, a deployment already dead-lettered, no handler for
 * the job type). The worker dead-letters the job on the first attempt
 * instead of retrying it max_attempts times with backoff.
 */
class NonRetryableJobException extends \RuntimeException
{
}

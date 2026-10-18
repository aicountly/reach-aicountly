<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Thrown by a job handler when the work cannot run *by design* right now —
 * the integration is not configured, the feature is switched off — as
 * opposed to failing. The worker records the job as `skipped` with this
 * reason: no retries, no dead letter, not counted as a failure.
 *
 * Use it only for conditions a retry cannot fix. A provider that is
 * configured but down is a failure and must still throw normally.
 */
class JobSkippedException extends \RuntimeException
{
}

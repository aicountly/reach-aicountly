<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

/** The routine must stop for today (automation off or daily quota used) — HTTP 429. */
final class VideoQuotaException extends \RuntimeException
{
}

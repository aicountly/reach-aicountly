<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

/** A remediation step that does not apply (HTTP status in the code). */
final class RemediationRefused extends \RuntimeException
{
}

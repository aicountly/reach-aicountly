<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

/**
 * The assistant could not be asked or gave no usable answer. Messages are
 * redacted (no API keys) and safe to store on the run row.
 */
final class AssistantException extends \RuntimeException
{
}

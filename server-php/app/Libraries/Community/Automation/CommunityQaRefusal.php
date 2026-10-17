<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

/**
 * A submission the routine must not retry as-is: automation off (503), quota
 * used (429), duplicate or already answered (409), unknown question (404),
 * question not answerable (422). The message is safe to return verbatim.
 */
final class CommunityQaRefusal extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Seo;

/**
 * A DataForSEO call that did not produce a usable SERP. `category` is one of
 * not_configured | auth | billing | rate_limited | transport | malformed | provider —
 * auth/billing stop a tracker run early (every further call would fail the same way).
 */
final class DataForSeoException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $category = 'provider')
    {
        parent::__construct($message);
    }

    public function isFatalForRun(): bool
    {
        return in_array($this->category, ['not_configured', 'auth', 'billing'], true);
    }
}

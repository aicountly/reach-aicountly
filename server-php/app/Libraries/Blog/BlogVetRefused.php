<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

/**
 * The publish gate would not let a blog version out: the vetting model did
 * not approve it (decision revise/reject), the version has no matching
 * approval, or vetting was unavailable (`unavailable`, retry later).
 */
final class BlogVetRefused extends \RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(
        string $message,
        public readonly string $decision = 'reject',
        public readonly array $reasons = [],
        public readonly bool $unavailable = false,
    ) {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Immutable result of a UrlPolicy::validate() decision.
 *
 * `$reason` and `$rule` are only populated when `$allowed === false`.
 * `$resolvedHost` may be populated when a host resolves to a private IP even
 * though the input hostname looked public — useful for debug logging.
 */
final class UrlPolicyResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $url,
        public readonly ?string $reason = null,
        public readonly ?string $rule = null,
        public readonly ?string $resolvedHost = null,
    ) {}

    public static function allow(string $url): self
    {
        return new self(true, $url);
    }

    public static function deny(string $url, string $rule, string $reason, ?string $resolvedHost = null): self
    {
        return new self(false, $url, $reason, $rule, $resolvedHost);
    }
}

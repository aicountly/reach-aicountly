<?php

namespace App\Libraries;

/**
 * Immutable result of ApprovalPolicy::canApprove().
 */
final class ApprovalPolicyResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $rule,
        public readonly string $risk,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
    ) {}

    public static function allowed(string $rule, string $risk = 'low', ?string $reason = null): self
    {
        return new self(true, $rule, $risk, $reason);
    }

    public static function denied(string $rule, string $message, string $risk = 'low'): self
    {
        return new self(false, $rule, $risk, null, $message);
    }
}

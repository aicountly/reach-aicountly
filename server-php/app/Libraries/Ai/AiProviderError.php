<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * Phase 3 — Classified provider error.
 *
 * message is safe to surface in logs and audit events.
 * It must never contain raw provider credentials or full secret-bearing responses.
 */
final class AiProviderError
{
    public const CATEGORY_CONFIGURATION     = 'configuration_error';
    public const CATEGORY_AUTHENTICATION    = 'authentication_error';
    public const CATEGORY_RATE_LIMITED      = 'rate_limited';
    public const CATEGORY_TIMEOUT           = 'timeout';
    public const CATEGORY_PROVIDER_UNAVAIL  = 'provider_unavailable';
    public const CATEGORY_NETWORK           = 'network_error';
    public const CATEGORY_INVALID_REQUEST   = 'invalid_request';
    public const CATEGORY_CONTEXT_LIMIT     = 'context_limit';
    public const CATEGORY_MALFORMED_OUTPUT  = 'malformed_output';
    public const CATEGORY_SCHEMA_VALIDATION = 'schema_validation_error';
    public const CATEGORY_CONTENT_BLOCKED   = 'content_blocked';
    public const CATEGORY_BUDGET_BLOCKED    = 'budget_blocked';
    public const CATEGORY_CANCELLED         = 'cancelled';
    public const CATEGORY_UNKNOWN           = 'unknown';

    private const RETRYABLE = [
        self::CATEGORY_RATE_LIMITED,
        self::CATEGORY_TIMEOUT,
        self::CATEGORY_PROVIDER_UNAVAIL,
        self::CATEGORY_NETWORK,
        self::CATEGORY_MALFORMED_OUTPUT,
    ];

    public function __construct(
        public readonly string $category,
        public readonly string $message,
        public readonly ?string $code = null,
    ) {
    }

    public function isRetryable(): bool
    {
        return in_array($this->category, self::RETRYABLE, true);
    }
}

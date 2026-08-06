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
    /**
     * The provider has withdrawn the model we asked for.
     *
     * Distinct from CATEGORY_CONFIGURATION because nothing local is wrong:
     * the route and the model row are valid, the model simply no longer
     * exists upstream. Retrying the same model can never succeed, but another
     * model can — so this must reach the fallback chain rather than failing
     * the request outright.
     */
    public const CATEGORY_MODEL_RETIRED     = 'model_retired';

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

    /**
     * Should the orchestrator try a different model rather than fail?
     *
     * Retrying the same model is pointless for these, but another model can
     * still serve the request: the quota is per-account, the key is per
     * provider, the withdrawn model is one row in a chain. Anything else —
     * schema validation, blocked content, a malformed prompt — would fail the
     * same way everywhere, so falling back only burns budget.
     *
     * Lives here rather than inline in the orchestrator so it can be tested
     * without standing up a provider: this list silently excluding
     * model_retired is what made a withdrawn model kill the whole request.
     */
    public function allowsFallback(): bool
    {
        return $this->isRetryable() || in_array($this->category, [
            self::CATEGORY_BUDGET_BLOCKED,
            self::CATEGORY_AUTHENTICATION,
            self::CATEGORY_CONFIGURATION,
            self::CATEGORY_PROVIDER_UNAVAIL,
            self::CATEGORY_MODEL_RETIRED,
        ], true);
    }
}

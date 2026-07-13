<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * Phase 3 — Classifies generic PHP exceptions into structured AI error categories.
 *
 * Used by provider adapters and the orchestrator for retry/fallback decisions.
 */
class AiErrorClassifier
{
    public function classify(\Throwable $error): AiProviderError
    {
        $msg = strtolower($error->getMessage());

        if (str_contains($msg, 'api key') || str_contains($msg, 'unauthorized') || str_contains($msg, '401')) {
            return new AiProviderError(AiProviderError::CATEGORY_AUTHENTICATION, 'Authentication failed.');
        }

        if (str_contains($msg, 'rate limit') || str_contains($msg, '429') || str_contains($msg, 'too many requests')) {
            return new AiProviderError(AiProviderError::CATEGORY_RATE_LIMITED, 'Rate limit reached.');
        }

        if (str_contains($msg, 'timeout') || str_contains($msg, 'timed out') || str_contains($msg, 'connection timed')) {
            return new AiProviderError(AiProviderError::CATEGORY_TIMEOUT, 'Request timed out.');
        }

        if (str_contains($msg, 'context length') || str_contains($msg, 'too many tokens') || str_contains($msg, 'maximum context')) {
            return new AiProviderError(AiProviderError::CATEGORY_CONTEXT_LIMIT, 'Context length exceeded.');
        }

        if (str_contains($msg, 'content policy') || str_contains($msg, 'content_filter') || str_contains($msg, 'safety')) {
            return new AiProviderError(AiProviderError::CATEGORY_CONTENT_BLOCKED, 'Content blocked by provider policy.');
        }

        if (str_contains($msg, 'service unavailable') || str_contains($msg, '503') || str_contains($msg, '502')) {
            return new AiProviderError(AiProviderError::CATEGORY_PROVIDER_UNAVAIL, 'Provider temporarily unavailable.');
        }

        if (str_contains($msg, 'json') || str_contains($msg, 'parse') || str_contains($msg, 'decode')) {
            return new AiProviderError(AiProviderError::CATEGORY_MALFORMED_OUTPUT, 'Provider returned malformed output.');
        }

        if (str_contains($msg, 'network') || str_contains($msg, 'connection refused') || str_contains($msg, 'could not resolve')) {
            return new AiProviderError(AiProviderError::CATEGORY_NETWORK, 'Network error reaching provider.');
        }

        if (str_contains($msg, '400') || str_contains($msg, 'invalid request') || str_contains($msg, 'bad request')) {
            return new AiProviderError(AiProviderError::CATEGORY_INVALID_REQUEST, 'Invalid request to provider.');
        }

        return new AiProviderError(AiProviderError::CATEGORY_UNKNOWN, 'Unexpected provider error.');
    }
}

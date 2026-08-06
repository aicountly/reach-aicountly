<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * Phase 3 — Classifies generic PHP exceptions into structured AI error categories.
 *
 * Used by provider adapters and the orchestrator for retry/fallback decisions.
 * Always preserves a redacted copy of the original provider message so ops can
 * diagnose failures (never replace everything with "Unexpected provider error").
 */
class AiErrorClassifier
{
    public function classify(\Throwable $error): AiProviderError
    {
        $raw = $this->safeMessage($error->getMessage());
        $msg = strtolower($raw);

        if (str_contains($msg, 'api key') || str_contains($msg, 'unauthorized') || str_contains($msg, '401')
            || str_contains($msg, 'incorrect api key') || str_contains($msg, 'authentication')) {
            return new AiProviderError(AiProviderError::CATEGORY_AUTHENTICATION, $raw);
        }

        if (str_contains($msg, 'rate limit') || str_contains($msg, '429') || str_contains($msg, 'too many requests')) {
            return new AiProviderError(AiProviderError::CATEGORY_RATE_LIMITED, $raw);
        }

        // A model the provider has withdrawn. Permanent for *this* model, but
        // the route's other models may be fine, so it must reach the fallback
        // resolver rather than dead-ending as 'unknown' — which is what turned
        // an OpenAI quota failure into eight failed community answers: the hop
        // to Gemini landed on the retired gemini-2.5-pro and stopped there.
        //
        // Runs ahead of the invalid-request rules below, which would otherwise
        // swallow the 404 Gemini reports this as. Distinct from
        // provider_unavailable because that category is retryable, and retrying
        // a withdrawn model spends an attempt on something that can never come
        // back; this one falls back without retrying.
        if (str_contains($msg, 'no longer available')
            || str_contains($msg, 'model not found')
            || str_contains($msg, 'model_not_found')
            || str_contains($msg, 'is not found')
            || str_contains($msg, 'does not exist')
            || str_contains($msg, 'has been deprecated')
            || str_contains($msg, 'is deprecated')
            // Deliberately not "model … not supported": OpenAI phrases a
            // request-shape rejection that way ("'max_tokens' is not supported
            // with this model"), which is an adapter bug, not a dead model.
            || (str_contains($msg, 'model') && str_contains($msg, 'retired'))) {
            return new AiProviderError(AiProviderError::CATEGORY_MODEL_RETIRED, $raw);
        }

        if (str_contains($msg, 'timeout') || str_contains($msg, 'timed out') || str_contains($msg, 'connection timed')) {
            return new AiProviderError(AiProviderError::CATEGORY_TIMEOUT, $raw);
        }

        if (str_contains($msg, 'context length') || str_contains($msg, 'too many tokens') || str_contains($msg, 'maximum context')) {
            return new AiProviderError(AiProviderError::CATEGORY_CONTEXT_LIMIT, $raw);
        }

        if (str_contains($msg, 'content policy') || str_contains($msg, 'content_filter') || str_contains($msg, 'safety')) {
            return new AiProviderError(AiProviderError::CATEGORY_CONTENT_BLOCKED, $raw);
        }

        if (str_contains($msg, 'service unavailable') || str_contains($msg, '503') || str_contains($msg, '502')) {
            return new AiProviderError(AiProviderError::CATEGORY_PROVIDER_UNAVAIL, $raw);
        }

        if (str_contains($msg, 'quota') || str_contains($msg, 'insufficient_quota') || str_contains($msg, 'billing')) {
            return new AiProviderError(AiProviderError::CATEGORY_BUDGET_BLOCKED, $raw);
        }

        if (str_contains($msg, 'ssl') || str_contains($msg, 'certificate') || str_contains($msg, 'curl error')
            || str_contains($msg, 'network') || str_contains($msg, 'connection refused')
            || str_contains($msg, 'could not resolve') || str_contains($msg, 'failed to connect')) {
            return new AiProviderError(AiProviderError::CATEGORY_NETWORK, $raw);
        }

        if (str_contains($msg, 'json_schema') || str_contains($msg, 'response_format')
            || str_contains($msg, 'invalid schema') || str_contains($msg, '400')
            || str_contains($msg, 'invalid request') || str_contains($msg, 'bad request')
            // Provider rejected the request shape, not its content — e.g.
            // "Unsupported parameter: 'max_tokens' is not supported with this
            // model." Classifying these as 'unknown' hid an adapter bug behind
            // the vaguest label we have.
            || str_contains($msg, 'unsupported parameter')
            || str_contains($msg, 'unsupported value')
            || str_contains($msg, 'unrecognized request argument')) {
            return new AiProviderError(AiProviderError::CATEGORY_INVALID_REQUEST, $raw);
        }

        if (str_contains($msg, 'json') || str_contains($msg, 'parse') || str_contains($msg, 'decode')) {
            return new AiProviderError(AiProviderError::CATEGORY_MALFORMED_OUTPUT, $raw);
        }

        return new AiProviderError(AiProviderError::CATEGORY_UNKNOWN, $raw !== '' ? $raw : 'Unexpected provider error.');
    }

    private function safeMessage(string $msg): string
    {
        $redacted = preg_replace('/sk-[A-Za-z0-9\-._]+/', '[REDACTED]', $msg) ?? $msg;
        $redacted = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $redacted) ?? $redacted;
        $redacted = preg_replace('/AIza[0-9A-Za-z\-_]{20,}/', '[REDACTED]', $redacted) ?? $redacted;

        return mb_substr(trim($redacted), 0, 400);
    }
}

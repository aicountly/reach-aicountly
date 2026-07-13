<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * Phase 3 — Thrown by provider adapters on generation failure.
 *
 * The message must be safe (no raw secrets or full provider headers).
 */
class AiProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly AiProviderError $providerError,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getProviderError(): AiProviderError
    {
        return $this->providerError;
    }

    public function isRetryable(): bool
    {
        return $this->providerError->isRetryable();
    }
}

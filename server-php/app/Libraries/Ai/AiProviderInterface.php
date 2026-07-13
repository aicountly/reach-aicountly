<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * Phase 3 — AI Provider contract.
 *
 * All provider adapters must implement this interface.
 * The interface is kept narrow so new providers can be added with minimal boilerplate.
 */
interface AiProviderInterface
{
    /**
     * Unique dotted key, e.g. 'openai', 'anthropic'.
     */
    public function getProviderKey(): string;

    /**
     * Returns true only when all required environment configuration is present.
     * An unconfigured provider must never be used for real generation.
     */
    public function isConfigured(): bool;

    /**
     * Perform a lightweight health/connectivity check.
     * Must not charge tokens if avoidable.
     */
    public function healthCheck(): AiProviderHealthResult;

    /**
     * Execute a single generation request.
     *
     * @throws AiProviderException on any provider-level error
     */
    public function generate(AiGenerationInput $input): AiGenerationResult;

    /**
     * Classify a throwable into a structured error category.
     */
    public function classifyError(\Throwable $error): AiProviderError;
}

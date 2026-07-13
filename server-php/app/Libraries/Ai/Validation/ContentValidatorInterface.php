<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation;

/**
 * Phase 3 — Contract for all AI validation pipeline validators.
 *
 * Both deterministic and AI-assisted validators implement this interface.
 * AI-assisted validators must use MockAiProvider in test environments.
 */
interface ContentValidatorInterface
{
    /**
     * Unique validator type slug, e.g. 'structured_output', 'pii_check'.
     */
    public function getType(): string;

    /**
     * True for AI-assisted validators (require a provider call).
     */
    public function isAiAssisted(): bool;

    /**
     * Execute the validator.
     *
     * @param array $content   Sanitised content output (parsedJson from artifact)
     * @param array $context   Grounding context, generation request, prompt version, etc.
     * @return ValidationFinding[]
     */
    public function validate(array $content, array $context): array;
}

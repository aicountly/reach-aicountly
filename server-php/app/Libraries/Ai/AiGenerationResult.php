<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * Phase 3 — Value object representing a successful provider response.
 *
 * parsedJson is set when structured output was requested and successfully decoded.
 * rawContent contains the provider's raw text (may be JSON string).
 */
final class AiGenerationResult
{
    public function __construct(
        public readonly string  $rawContent,
        public readonly ?array  $parsedJson,
        public readonly int     $inputTokens,
        public readonly int     $outputTokens,
        public readonly int     $totalTokens,
        public readonly ?string $providerResponseId,
        public readonly int     $durationMs,
        public readonly string  $modelKey,
        public readonly string  $providerKey,
    ) {
    }

    public function estimatedCost(float $inputCostPer1k, float $outputCostPer1k): float
    {
        return ($this->inputTokens / 1000.0 * $inputCostPer1k)
             + ($this->outputTokens / 1000.0 * $outputCostPer1k);
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

/**
 * Phase 3 — Value object representing a single provider generation request.
 *
 * No secrets are stored here. The requestId is used for correlation only.
 */
final class AiGenerationInput
{
    public function __construct(
        public readonly string  $systemPrompt,
        public readonly string  $userPrompt,
        public readonly array   $outputSchema,
        public readonly string  $modelKey,
        public readonly int     $maxOutputTokens = 4096,
        public readonly int     $timeoutSeconds  = 30,
        public readonly ?string $requestId       = null,
        public readonly array   $extraParams     = [],
    ) {
    }

    public function withRequestId(string $requestId): self
    {
        return new self(
            $this->systemPrompt,
            $this->userPrompt,
            $this->outputSchema,
            $this->modelKey,
            $this->maxOutputTokens,
            $this->timeoutSeconds,
            $requestId,
            $this->extraParams,
        );
    }
}

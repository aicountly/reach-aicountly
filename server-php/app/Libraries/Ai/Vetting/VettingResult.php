<?php

namespace App\Libraries\Ai\Vetting;

/**
 * The vetting model's verdict on one draft. `final` holds the text to
 * publish — the draft as submitted, or the model's corrected version — and is
 * only meaningful when the decision is approve or revise.
 */
final class VettingResult
{
    public const APPROVE = 'approve';
    public const REVISE  = 'revise';
    public const REJECT  = 'reject';

    /**
     * @param array<string, mixed> $final
     * @param list<string>         $reasons
     * @param array<string, int>   $scores
     */
    public function __construct(
        public readonly string $decision,
        public readonly array $final,
        public readonly array $reasons,
        public readonly array $scores,
        public readonly string $model,
        public readonly ?int $runId = null,
    ) {
    }

    public function approved(): bool
    {
        return $this->decision === self::APPROVE;
    }

    public function rejected(): bool
    {
        return $this->decision === self::REJECT;
    }
}

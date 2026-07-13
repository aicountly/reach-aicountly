<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation;

/**
 * Phase 3 — Value object for a single validator result.
 */
final class ValidationFinding
{
    public const STATUS_PASSED       = 'passed';
    public const STATUS_WARNING      = 'warning';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public function __construct(
        public readonly string  $validatorType,
        public readonly string  $status,
        public readonly string  $severity,
        public readonly string  $title,
        public readonly string  $message,
        public readonly ?string $affectedField   = null,
        public readonly ?array  $details         = null,
        public readonly ?string $suggestedFix    = null,
        public readonly bool    $isAiAssisted    = false,
    ) {
    }

    public function isBlocking(): bool
    {
        return $this->status === self::STATUS_FAILED && in_array($this->severity, [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL], true);
    }

    public function toArray(): array
    {
        return [
            'validator_type' => $this->validatorType,
            'status'         => $this->status,
            'severity'       => $this->severity,
            'title'          => $this->title,
            'message'        => $this->message,
            'affected_field' => $this->affectedField,
            'details'        => $this->details,
            'suggested_fix'  => $this->suggestedFix,
            'is_ai_assisted' => $this->isAiAssisted,
        ];
    }
}

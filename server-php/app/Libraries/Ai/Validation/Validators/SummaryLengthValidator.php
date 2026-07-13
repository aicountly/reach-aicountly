<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

class SummaryLengthValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'summary_length'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $summary = $content['summary'] ?? '';
        $length  = mb_strlen(trim($summary));

        if ($length === 0) {
            return [new ValidationFinding('summary_length', ValidationFinding::STATUS_FAILED, ValidationFinding::SEVERITY_HIGH, 'Summary missing', 'Content must have a summary.')];
        }

        if ($length > 1024) {
            return [new ValidationFinding('summary_length', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Summary too long', "Summary is {$length} characters; maximum is 1024.", 'summary')];
        }

        return [new ValidationFinding('summary_length', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Summary length acceptable', "Summary length: {$length}.")];
    }
}

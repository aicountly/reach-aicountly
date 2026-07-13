<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

class EmailSubjectLineLengthValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'email_subject_length'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $ct = $context['content_type'] ?? '';
        if (! in_array($ct, ['email_campaign', 'newsletter'], true)) {
            return [new ValidationFinding('email_subject_length', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Not applicable', 'Only applies to email types.')];
        }

        $subject = $content['subject_line'] ?? '';
        $length  = mb_strlen($subject);

        if ($length === 0) {
            return [new ValidationFinding('email_subject_length', ValidationFinding::STATUS_FAILED, ValidationFinding::SEVERITY_HIGH, 'Missing subject line', 'Email must have a subject line.')];
        }

        if ($length > 70) {
            return [new ValidationFinding('email_subject_length', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Subject line too long', "{$length} chars; aim for under 70 chars.", 'subject_line')];
        }

        return [new ValidationFinding('email_subject_length', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Subject line length OK', "{$length} characters.")];
    }
}

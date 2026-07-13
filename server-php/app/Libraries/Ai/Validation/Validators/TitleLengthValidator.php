<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

class TitleLengthValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'title_length'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $title  = $content['title'] ?? '';
        $length = mb_strlen($title);

        if ($length === 0) {
            return [new ValidationFinding('title_length', ValidationFinding::STATUS_FAILED, ValidationFinding::SEVERITY_HIGH, 'Title missing', 'Content must have a title.', 'title')];
        }

        if ($length > 120) {
            return [new ValidationFinding('title_length', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Title too long', "Title is {$length} characters; recommended maximum is 120.", 'title', null, 'Shorten the title to under 120 characters.')];
        }

        if ($length < 10) {
            return [new ValidationFinding('title_length', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Title too short', "Title is only {$length} characters.", 'title')];
        }

        return [new ValidationFinding('title_length', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Title length acceptable', "Title length: {$length} characters.")];
    }
}

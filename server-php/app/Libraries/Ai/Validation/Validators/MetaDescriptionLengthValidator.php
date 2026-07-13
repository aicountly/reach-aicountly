<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

class MetaDescriptionLengthValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'meta_description_length'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $meta = $content['meta_description'] ?? null;

        if ($meta === null) {
            return [new ValidationFinding('meta_description_length', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'No meta description', 'Not applicable for this content type.')];
        }

        $length = mb_strlen($meta);

        if ($length < 50) {
            return [new ValidationFinding('meta_description_length', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Meta description too short', "Meta description is {$length} chars; minimum recommended is 50.", 'meta_description')];
        }

        if ($length > 160) {
            return [new ValidationFinding('meta_description_length', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Meta description too long', "Meta description is {$length} chars; maximum recommended is 160.", 'meta_description', null, 'Shorten the meta description to 50-160 characters.')];
        }

        return [new ValidationFinding('meta_description_length', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Meta description length acceptable', "Meta description length: {$length} characters.")];
    }
}

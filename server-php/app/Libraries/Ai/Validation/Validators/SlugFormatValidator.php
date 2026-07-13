<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

class SlugFormatValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'slug_format'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $slug = $content['slug_suggestion'] ?? null;

        if ($slug === null) {
            return [new ValidationFinding('slug_format', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'No slug', 'Not applicable.')];
        }

        if (! preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/', $slug)) {
            return [new ValidationFinding('slug_format', ValidationFinding::STATUS_FAILED, ValidationFinding::SEVERITY_WARNING, 'Invalid slug format', "Slug '{$slug}' must be lowercase alphanumeric with hyphens only.", 'slug_suggestion', null, 'Convert slug to lowercase letters, numbers and hyphens only.')];
        }

        return [new ValidationFinding('slug_format', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Slug format valid', "Slug: {$slug}")];
    }
}

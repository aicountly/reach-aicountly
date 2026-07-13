<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Checks that the body text does not mention 'planned' or 'coming soon' features
 * that should not be publicly claimed as available.
 */
class FeatureAvailabilityValidator implements ContentValidatorInterface
{
    private const PLANNED_FEATURES_HINTS = [
        'coming soon', 'planned feature', 'roadmap feature', 'future release', 'not yet available',
    ];

    public function getType(): string { return 'feature_availability'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $body  = strtolower(strip_tags($content['body_html'] ?? $content['body_plain_text'] ?? ''));
        $found = [];

        foreach (self::PLANNED_FEATURES_HINTS as $hint) {
            if (str_contains($body, $hint)) {
                $found[] = $hint;
            }
        }

        // Also check against planned features in grounding
        $features = $context['grounding']['features'] ?? [];
        $planned  = array_filter($features, fn($f) => ($f['availability'] ?? '') === 'planned');

        foreach ($planned as $pf) {
            $name = strtolower($pf['name'] ?? '');
            if ($name && str_contains($body, $name)) {
                $found[] = 'planned feature: ' . ($pf['name'] ?? '');
            }
        }

        if (! empty($found)) {
            return [new ValidationFinding(
                'feature_availability',
                ValidationFinding::STATUS_FAILED,
                ValidationFinding::SEVERITY_HIGH,
                'Planned feature referenced',
                'Content references features that are not yet available: ' . implode(', ', array_unique($found)),
                null,
                ['references' => array_unique($found)],
                'Remove references to planned or unavailable features.',
            )];
        }

        return [new ValidationFinding('feature_availability', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Feature availability check passed', 'No references to planned features detected.')];
    }
}

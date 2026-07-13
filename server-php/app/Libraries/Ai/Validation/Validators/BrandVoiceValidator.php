<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Deterministic brand voice check: detects forbidden phrases from brand_rules grounding.
 */
class BrandVoiceValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'brand_voice'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $brandRules = $context['grounding']['brand_rules'] ?? [];
        $bodyText   = strtolower(strip_tags($content['body_html'] ?? $content['body_plain_text'] ?? ''));
        $titleText  = strtolower($content['title'] ?? '');
        $fullText   = $bodyText . ' ' . $titleText;

        $violations = [];

        foreach ($brandRules as $rule) {
            if (($rule['rule_type'] ?? '') === 'forbidden_phrase') {
                $phrase = strtolower($rule['rule_value'] ?? '');
                if ($phrase && str_contains($fullText, $phrase)) {
                    $violations[] = $rule['rule_value'];
                }
            }
        }

        if (! empty($violations)) {
            return [new ValidationFinding(
                'brand_voice',
                ValidationFinding::STATUS_FAILED,
                ValidationFinding::SEVERITY_HIGH,
                'Brand voice violation',
                'Content uses forbidden phrases: ' . implode(', ', $violations),
                null,
                ['forbidden_phrases' => $violations],
                'Remove the forbidden phrase(s) and replace with brand-approved alternatives.',
            )];
        }

        return [new ValidationFinding('brand_voice', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Brand voice check passed', 'No forbidden phrases detected.')];
    }
}

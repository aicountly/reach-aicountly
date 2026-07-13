<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Checks content against active content policies from grounding context.
 * Only deterministic rules are checked here (AI-assisted check is in AiToneValidator).
 */
class ContentPolicyValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'content_policy'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $policies = $context['grounding']['content_policies'] ?? [];
        $bodyText = strtolower(strip_tags($content['body_html'] ?? $content['body_plain_text'] ?? ''));
        $violations = [];

        foreach ($policies as $policy) {
            if (($policy['rule_type'] ?? '') === 'blocked_keyword') {
                $keyword = strtolower($policy['rule_value'] ?? '');
                if ($keyword && str_contains($bodyText, $keyword)) {
                    $violations[] = ['policy' => $policy['slug'] ?? 'unknown', 'keyword' => $policy['rule_value']];
                }
            }
        }

        if (! empty($violations)) {
            return [new ValidationFinding(
                'content_policy',
                ValidationFinding::STATUS_FAILED,
                ValidationFinding::SEVERITY_CRITICAL,
                'Content policy violation',
                'Content violates active content policies.',
                null,
                ['violations' => $violations],
                'Remove policy-violating keywords from the content.',
            )];
        }

        return [new ValidationFinding('content_policy', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Content policy check passed', 'No content policy violations detected.')];
    }
}

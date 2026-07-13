<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Checks that all claim IDs in claims_used exist in the approved grounding context.
 * Unknown claim IDs could indicate hallucinated facts.
 */
class ProductClaimAccuracyValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'product_claim_accuracy'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $claimsUsed     = $content['claims_used'] ?? [];
        $claimsApproved = array_column($context['grounding']['claims'] ?? [], 'id');

        if (empty($claimsUsed)) {
            return [new ValidationFinding('product_claim_accuracy', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'No claims to verify', 'No claims were referenced.')];
        }

        $hallucinated = array_diff($claimsUsed, array_map('strval', $claimsApproved));

        if (! empty($hallucinated)) {
            return [new ValidationFinding(
                'product_claim_accuracy',
                ValidationFinding::STATUS_FAILED,
                ValidationFinding::SEVERITY_HIGH,
                'Unverified claims detected',
                'Content references claim IDs not present in approved grounding: ' . implode(', ', $hallucinated),
                'claims_used',
                ['hallucinated_ids' => array_values($hallucinated)],
                'Remove or replace claims not present in the approved knowledge base.',
            )];
        }

        return [new ValidationFinding('product_claim_accuracy', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'All claims verified', count($claimsUsed) . ' claim(s) verified.')];
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Checks that claims_used is not empty if the grounding context contained claims.
 * Missing claims in content derived from a claim-rich grounding is suspicious.
 */
class ClaimsReferencedValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'claims_referenced'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $claimsInGrounding = count($context['grounding']['claims'] ?? []);
        $claimsUsed        = count($content['claims_used'] ?? []);

        if ($claimsInGrounding > 0 && $claimsUsed === 0) {
            return [new ValidationFinding('claims_referenced', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'No claims referenced', 'Grounding context had ' . $claimsInGrounding . ' claims but none were referenced in output.', 'claims_used', null, 'Reference at least one claim from the grounding context.')];
        }

        return [new ValidationFinding('claims_referenced', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Claims check passed', "Claims referenced: {$claimsUsed}.")];
    }
}

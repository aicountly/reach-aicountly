<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Flags content that has risk_notes as informational for human reviewers.
 */
class RiskNotesValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'risk_notes'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $notes = $content['risk_notes'] ?? [];

        if (! empty($notes)) {
            return [new ValidationFinding(
                'risk_notes',
                ValidationFinding::STATUS_WARNING,
                ValidationFinding::SEVERITY_WARNING,
                'Risk notes present',
                'The AI flagged ' . count($notes) . ' risk note(s) that require human review: ' . implode('; ', array_slice($notes, 0, 3)),
                'risk_notes',
                ['notes' => $notes],
                'Review each risk note before approving this content.',
            )];
        }

        return [new ValidationFinding('risk_notes', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'No risk notes', 'AI did not flag any risk notes.')];
    }
}

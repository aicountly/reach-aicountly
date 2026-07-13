<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Warns if content types that typically require a CTA don't have one.
 */
class CallToActionPresenceValidator implements ContentValidatorInterface
{
    private const REQUIRES_CTA = ['landing_page', 'email_campaign', 'ad_copy', 'newsletter', 'product_description'];

    public function getType(): string { return 'cta_presence'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $contentType = $context['content_type'] ?? '';

        if (! in_array($contentType, self::REQUIRES_CTA, true)) {
            return [new ValidationFinding('cta_presence', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'CTA not required', 'Not required for ' . $contentType)];
        }

        $cta = trim($content['primary_cta'] ?? '');
        if (empty($cta)) {
            return [new ValidationFinding('cta_presence', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Missing CTA', "Content type '{$contentType}' should have a primary call-to-action.", 'primary_cta', null, 'Add a clear call-to-action button text.')];
        }

        return [new ValidationFinding('cta_presence', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'CTA present', "CTA: {$cta}")];
    }
}

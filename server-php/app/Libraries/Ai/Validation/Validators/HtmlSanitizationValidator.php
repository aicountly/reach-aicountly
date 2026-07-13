<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Checks for dangerous HTML patterns: script tags, event handlers, javascript: URIs.
 */
class HtmlSanitizationValidator implements ContentValidatorInterface
{
    private const DANGEROUS_PATTERNS = [
        '/<script\b/i'                    => 'script tag',
        '/on[a-z]+\s*=/i'                 => 'inline event handler',
        '/javascript\s*:/i'               => 'javascript: URI',
        '/data\s*:\s*text\/html/i'        => 'data:text/html URI',
        '/<\s*iframe\b/i'                 => 'iframe element',
        '/<\s*object\b/i'                 => 'object element',
        '/<\s*embed\b/i'                  => 'embed element',
    ];

    public function getType(): string { return 'html_sanitization'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $html = $content['body_html'] ?? '';

        if (empty($html)) {
            return [new ValidationFinding('html_sanitization', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'No HTML body', 'Not applicable.')];
        }

        $found = [];
        foreach (self::DANGEROUS_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $html)) {
                $found[] = $label;
            }
        }

        if (! empty($found)) {
            return [new ValidationFinding(
                'html_sanitization',
                ValidationFinding::STATUS_FAILED,
                ValidationFinding::SEVERITY_CRITICAL,
                'Dangerous HTML detected',
                'HTML body contains potentially dangerous elements: ' . implode(', ', $found),
                'body_html',
                ['detected' => $found],
                'Remove all script tags, event handlers, and javascript: URIs from the HTML.',
            )];
        }

        return [new ValidationFinding('html_sanitization', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'HTML sanitization passed', 'No dangerous HTML patterns detected.')];
    }
}

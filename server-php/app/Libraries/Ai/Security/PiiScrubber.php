<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Security;

/**
 * Phase 3 — PII Scrubber.
 *
 * Replaces common PII patterns in text with redacted placeholders before
 * the text is included in AI prompts or stored as grounding context.
 *
 * This is a best-effort defence-in-depth control. It is not a guarantee
 * of full PII elimination. The intent is to prevent obvious leakage of
 * email addresses, phone numbers, credit card numbers, national IDs, etc.
 */
class PiiScrubber
{
    private const RULES = [
        // Email addresses
        ['/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[EMAIL]'],
        // Credit/debit card numbers (13-19 digits, optionally space-separated) — before phone to avoid false positives
        ['/\b(?:\d[ \-]?){13,19}\b/', '[CARD_NUMBER]'],
        // Phone numbers (international E.164 and common local formats)
        ['/(\+?[1-9]\d{1,3}[\s\-.]?)?\(?\d{2,4}\)?[\s\-.]?\d{3,4}[\s\-.]?\d{4}/', '[PHONE]'],
        // Social security / national ID (###-##-####)
        ['/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/', '[NATIONAL_ID]'],
        // UK National Insurance number
        ['/\b[A-CEGHJ-PR-TW-Z]{2}\d{6}[A-D]?\b/', '[NI_NUMBER]'],
        // Passport-style (letter + 7–8 digits)
        ['/\b[A-Z]{1,2}\d{7,8}\b/', '[PASSPORT]'],
        // IPv4 addresses
        ['/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP_ADDRESS]'],
        // Date of birth patterns (DD/MM/YYYY and similar)
        ['/\b(0?[1-9]|[12]\d|3[01])[\/\-](0?[1-9]|1[0-2])[\/\-](19|20)\d{2}\b/', '[DOB]'],
    ];

    /**
     * Replace PII in $text with placeholders.
     */
    public function scrub(string $text): string
    {
        foreach (self::RULES as [$pattern, $replacement]) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }
        return $text;
    }

    /**
     * Returns true if PII patterns are found in the text.
     */
    public function contains(string $text): bool
    {
        foreach (self::RULES as [$pattern]) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scrub an array recursively (string leaves only).
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function scrubArray(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (is_string($value)) {
                $value = $this->scrub($value);
            }
        });
        return $data;
    }
}

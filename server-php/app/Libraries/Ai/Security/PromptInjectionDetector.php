<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Security;

/**
 * Phase 3 — Prompt Injection Detector.
 *
 * Scans user-supplied text for common prompt injection patterns before
 * including it in any system or user prompt. Detection is deterministic
 * and never calls an external provider.
 *
 * Detection does NOT block automatically — the caller decides whether to
 * reject, sanitise, or warn. This keeps policy decisions outside the detector.
 */
class PromptInjectionDetector
{
    /**
     * Patterns that indicate a prompt injection attempt.
     * These target classic "jailbreak" and system-override tricks.
     */
    private const PATTERNS = [
        // System override attempts
        '/ignore\s+(all\s+)?(previous|prior|above|earlier)\s+instruction/i',
        '/disregard\s+(all\s+)?(previous|prior|above|earlier)\s+instruction/i',
        '/forget\s+(all\s+)?(previous|prior|above|earlier)\s+instruction/i',
        '/override\s+(all\s+)?(previous|prior|above|earlier)\s+instruction/i',
        // Role reassignment
        '/you\s+are\s+now\s+(a|an|the)\s+/i',
        '/act\s+as\s+(a|an|the|if)\s+/i',
        '/pretend\s+(you\s+are|to\s+be)\s+/i',
        '/roleplay\s+as\s+/i',
        // DAN / jailbreak vocabulary
        '/\bDAN\b/',
        '/jailbreak/i',
        '/developer\s+mode/i',
        // Direct system prompt exposure
        '/print\s+(your\s+)?(system\s+prompt|instructions|rules|context)/i',
        '/reveal\s+(your\s+)?(system\s+prompt|instructions|rules|context)/i',
        '/show\s+(me\s+)?(your\s+)?(system\s+prompt|instructions|rules)/i',
        '/repeat\s+(your\s+)?(system\s+prompt|instructions)/i',
        '/output\s+(your\s+)?(full\s+)?(system\s+prompt|prompt|instructions)/i',
        // Base64 / encoding tricks
        '/base64\s+decode/i',
        '/decode\s+this\s+base64/i',
        // Multi-line override injection via delimiters
        '/---+\s*(system|user|assistant)\s*---+/i',
        '/\[INST\]|\[\/INST\]/i',
        '/<\|im_start\|>|<\|im_end\|>/i',
        // Token smuggling
        '/\\\u[0-9a-fA-F]{4}.*instruction/i',
    ];

    /**
     * @param string $text Raw user-supplied text to scan.
     * @return bool true if injection patterns are detected.
     */
    public function detect(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the first matched pattern description, or null if clean.
     */
    public function firstMatch(string $text): ?string
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $pattern;
            }
        }
        return null;
    }

    /**
     * Sanitise by stripping detected phrases (best-effort; prefer rejection over sanitising).
     */
    public function sanitise(string $text): string
    {
        foreach (self::PATTERNS as $pattern) {
            $text = (string) preg_replace($pattern, '[REDACTED]', $text);
        }
        return $text;
    }
}

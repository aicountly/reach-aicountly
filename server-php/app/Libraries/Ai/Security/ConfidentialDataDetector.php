<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Security;

/**
 * Phase 3 — Confidential Data Detector.
 *
 * Identifies potential confidential data patterns (API keys, secrets,
 * tokens, passwords, internal notes) in text before including it in
 * AI prompts or grounding context.
 *
 * Detection is heuristic and pattern-based. It supplements but does not
 * replace proper access control — content that should not exist in the
 * knowledge base should be blocked at ingestion time via `is_confidential`
 * and `internal_only` flags.
 */
class ConfidentialDataDetector
{
    private const PATTERNS = [
        // AWS-style access key
        ['/\bAKIA[0-9A-Z]{16}\b/', 'aws_access_key'],
        // Generic API key patterns
        ['/\b(api[_\-]?key|apikey|api_secret|client_secret)\s*[=:]\s*["\']?[A-Za-z0-9_\-]{16,}["\']?/i', 'api_key'],
        // Bearer tokens / JWT
        ['/\bBearer\s+[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\b/', 'bearer_token'],
        // JWT structure without Bearer prefix
        ['/\beyJ[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{20,}\b/', 'jwt_token'],
        // Database connection strings
        ['/\b(postgres|mysql|mongodb|redis):\/\/[^\s]{8,}/', 'db_connection_string'],
        // Password fields
        ['/\b(password|passwd|pwd)\s*[=:]\s*["\']?[^\s"\']{6,}["\']?/i', 'password'],
        // Private key headers
        ['/-----BEGIN\s+(RSA\s+)?PRIVATE\s+KEY-----/', 'private_key'],
        // Stripe-style secret keys
        ['/\bsk_(?:live|test)_[A-Za-z0-9]{24,}\b/', 'stripe_secret_key'],
        // OpenAI API key pattern
        ['/\bsk-[A-Za-z0-9]{48}\b/', 'openai_api_key'],
        // Slack bot/webhook tokens
        ['/\bxox[bprs]-[0-9A-Za-z\-]{20,}\b/', 'slack_token'],
        // Internal note markers
        ['/\[\s*(?:INTERNAL|CONFIDENTIAL|DO NOT SHARE|PRIVATE|SECRET)\s*\]/i', 'internal_marker'],
    ];

    /**
     * @return array{type:string, pattern:string}[] list of detections, empty if clean
     */
    public function detect(string $text): array
    {
        $findings = [];
        foreach (self::PATTERNS as [$pattern, $type]) {
            if (preg_match($pattern, $text) === 1) {
                $findings[] = ['type' => $type, 'pattern' => $pattern];
            }
        }
        return $findings;
    }

    /**
     * Returns true if any confidential patterns are detected.
     */
    public function isClean(string $text): bool
    {
        return empty($this->detect($text));
    }

    /**
     * Redact detected confidential data patterns.
     */
    public function redact(string $text): string
    {
        foreach (self::PATTERNS as [$pattern, $type]) {
            $text = (string) preg_replace($pattern, "[REDACTED:{$type}]", $text);
        }
        return $text;
    }
}

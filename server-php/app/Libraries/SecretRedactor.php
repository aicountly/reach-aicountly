<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\SensitiveSettings;

/**
 * Deep-walks arbitrary payloads and replaces the values of any keys that
 * look like secrets with a fixed placeholder.
 *
 * Also detects bearer-shaped strings inside otherwise-innocuous keys (e.g.
 * a raw JWT accidentally passed into a `metadata` field) and redacts them.
 *
 * Rules:
 *   1. Key names matching SensitiveSettings::isSensitive() -> "[REDACTED]".
 *   2. String values matching bearer/JWT/hex-secret shapes -> "[REDACTED]".
 *   3. Depth-limited to prevent runaway recursion on cyclic arrays.
 *   4. Scalars pass through unchanged when they don't match anything.
 */
class SecretRedactor
{
    private const PLACEHOLDER = '[REDACTED]';
    private const MAX_DEPTH   = 8;

    private SensitiveSettings $sensitive;

    public function __construct(?SensitiveSettings $sensitive = null)
    {
        $this->sensitive = $sensitive ?? config(SensitiveSettings::class);
    }

    /**
     * Redact any value type. Scalars are inspected directly; arrays walk
     * key-by-key. Objects are cast to array to avoid holding references.
     */
    public function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return self::PLACEHOLDER;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && $this->sensitive->isSensitive($k)) {
                    $out[$k] = self::PLACEHOLDER;
                    continue;
                }
                $out[$k] = $this->redact($v, $depth + 1);
            }
            return $out;
        }

        if (is_object($value)) {
            return $this->redact((array) $value, $depth + 1);
        }

        if (is_string($value) && $this->looksLikeSecret($value)) {
            return self::PLACEHOLDER;
        }

        return $value;
    }

    /**
     * Redact only the values for the requested keys — used when a controller
     * knows precisely which fields carry secrets and wants to leave the rest
     * of the payload untouched.
     */
    public function redactKeys(array $data, array $keys): array
    {
        $lookup = array_flip(array_map('strtolower', $keys));
        foreach ($data as $k => $v) {
            if (is_string($k) && isset($lookup[strtolower($k)])) {
                $data[$k] = self::PLACEHOLDER;
            }
        }
        return $data;
    }

    /**
     * Return true if a raw string is shaped like a token/JWT/secret.
     *
     * Heuristics (deliberately conservative to avoid over-redacting user
     * content):
     *   - Starts with "Bearer " (case-insensitive).
     *   - Looks like a JWT (3 base64url segments separated by dots).
     *   - Long (>=32) contiguous alphanumeric-ish blob (base64/hex) with
     *     no whitespace — typical for API keys.
     */
    private function looksLikeSecret(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (stripos($value, 'bearer ') === 0) {
            return true;
        }
        if (preg_match('/^[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}$/', $value)) {
            return true;
        }
        if (strlen($value) >= 32 && strpos($value, ' ') === false
            && preg_match('/^[A-Za-z0-9+\/=_-]+$/', $value)) {
            return true;
        }
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Insights;

/**
 * Lists the figures in a model's report that do not appear in the data
 * digest it was given. The insights prompt forbids the model from inventing
 * numbers; this is the check that it complied, shown to the reader next to
 * the report rather than trusted silently.
 *
 * Heuristic by design: small counts (≤ 10), years, and durations
 * ("14 days", "2 weeks") are plans rather than measurements and are not
 * flagged; text under `assumptions` is the model's labelled guesswork and is
 * skipped. A digest ratio (0.034) also matches its percentage (3.4%), and a
 * figure may be the digest's rounded to fewer decimals — nothing looser.
 */
final class NumberAudit
{
    private const SKIP_KEYS = ['assumptions', 'horizon_days', 'effort', 'expected_impact', 'owner_type', 'status', 'key'];

    /**
     * @param array<string, mixed> $digest
     * @param array<string, mixed> $report
     * @return list<string> the untraceable figures as written
     */
    public static function unverified(array $digest, array $report): array
    {
        $known = [];
        self::collectDigest($digest, $known);

        $found = [];
        self::collectReport($report, $found, '');

        $out = [];
        foreach ($found as [$written, $value]) {
            if (! self::matches($value, $known)) {
                $out[$written] = true;
            }
        }

        return array_slice(array_map('strval', array_keys($out)), 0, 50);
    }

    /**
     * @param list<float> $known
     */
    private static function matches(float $value, array $known): bool
    {
        $isInt = floor($value) === $value;
        foreach ($known as $k) {
            foreach ([$k, $k * 100] as $c) {
                // Same figure, or the digest's figure rounded to fewer decimals ("2.17%" for 0.02174).
                if (abs($value - $c) <= 0.051 || ($isInt && floor($c) !== $c && abs($value - $c) < 0.5)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param mixed       $node
     * @param list<float> $known
     */
    private static function collectDigest(mixed $node, array &$known): void
    {
        if (is_int($node) || is_float($node)) {
            $known[] = (float) $node;
            return;
        }
        if (is_string($node)) {
            foreach (self::numbersIn($node, false) as [, $v]) {
                $known[] = $v;
            }
            return;
        }
        if (is_array($node)) {
            foreach ($node as $v) {
                self::collectDigest($v, $known);
            }
        }
    }

    /**
     * @param mixed                            $node
     * @param list<array{0: string, 1: float}> $found
     */
    private static function collectReport(mixed $node, array &$found, string $key): void
    {
        if (in_array($key, self::SKIP_KEYS, true)) {
            return;
        }
        if (is_string($node)) {
            foreach (self::numbersIn($node, true) as $hit) {
                $found[] = $hit;
            }
            return;
        }
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                self::collectReport($v, $found, is_string($k) ? $k : $key);
            }
        }
    }

    /**
     * @return list<array{0: string, 1: float}> [as written, numeric value]
     */
    public static function numbersIn(string $text, bool $filter): array
    {
        $out = [];
        if (preg_match_all('/(?<![\p{L}\p{N}_.\-\/#])(\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|\d+(?:\.\d+)?)(\s*%)?(?![\p{L}\p{N}_\/])/u', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($m as $hit) {
            $written = $hit[0][0];
            $raw     = str_replace(',', '', $hit[1][0]);
            $value   = (float) $raw;
            if ($filter) {
                $after = substr($text, $hit[0][1] + strlen($written), 24);
                $isPct = isset($hit[2]) && trim((string) $hit[2][0]) === '%';
                if (! $isPct && ! str_contains($raw, '.') && $value <= 10) {
                    continue;
                }
                if (! $isPct && ! str_contains($raw, '.') && $value >= 1990 && $value <= 2100) {
                    continue;
                }
                if (preg_match('/^\s*(?:-\s*)?(?:days?|weeks?|months?|hours?|minutes?|mins?|years?|quarters?|words?|seconds?|x\b)/iu', $after) === 1) {
                    continue;
                }
                // "-" after a number is a range ("10-15") or a hyphenated unit; the next token decides.
                if (str_starts_with($after, '-') && preg_match('/^-\s*\d/u', $after) === 1 && preg_match('/^-\s*\d+\s*(?:days?|weeks?|months?)/iu', $after) === 1) {
                    continue;
                }
            }
            $out[] = [trim($written), $value];
        }

        return $out;
    }
}

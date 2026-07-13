<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Grounding;

/**
 * Phase 3 — Enforces token and character size limits on grounding context.
 *
 * Grounding context must not exceed the configured limits to leave enough
 * room for prompts and AI output within the model's context window.
 *
 * Priority order when trimming: brand_rules > policies > claims > features > modules > products > personas
 */
class GroundingSizeLimiter
{
    /** Maximum grounding context characters (approx 12k tokens @ 4 chars/token) */
    private const DEFAULT_MAX_CHARS = 48000;

    /** Approximate characters-per-token ratio */
    private const CHARS_PER_TOKEN = 4;

    private int $maxChars;

    public function __construct(int $maxChars = self::DEFAULT_MAX_CHARS)
    {
        $this->maxChars = $maxChars;
    }

    /**
     * Trims a grounding context to fit within the character limit.
     * Lower-priority sections are truncated first.
     *
     * @param array $context  Full grounding context
     * @return array          Trimmed grounding context with __truncated marker
     */
    public function limit(array $context): array
    {
        $json = json_encode($context);
        if (strlen($json) <= $this->maxChars) {
            return $context;
        }

        // Trim sections in reverse priority
        $trimOrder = ['evidence', 'sources', 'personas', 'industries', 'features', 'modules', 'claims', 'brand_rules', 'policies'];

        foreach ($trimOrder as $section) {
            if (! isset($context[$section]) || ! is_array($context[$section])) {
                continue;
            }

            while (count($context[$section]) > 0 && strlen(json_encode($context)) > $this->maxChars) {
                array_pop($context[$section]);
                $context['__truncated'][] = $section;
            }

            if (strlen(json_encode($context)) <= $this->maxChars) {
                break;
            }
        }

        $context['__truncated'] = array_unique($context['__truncated'] ?? []);

        return $context;
    }

    /**
     * Estimates token count for a context array.
     */
    public function estimateTokens(array $context): int
    {
        return (int) (strlen(json_encode($context)) / self::CHARS_PER_TOKEN);
    }

    public function exceedsLimit(array $context): bool
    {
        return strlen(json_encode($context)) > $this->maxChars;
    }
}

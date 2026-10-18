<?php

declare(strict_types=1);

namespace App\Libraries\Content;

/**
 * Cheap, offline checks that catch the failure family the retired in-Reach
 * generation path produced: a body that is not about its title, filler, or
 * text about the generation itself ("the intent 'draft_generation'", "the
 * grounding context", "content pipeline").
 *
 * The heuristics only ever *flag*. They never retire or unpublish anything on
 * their own — that needs the vetting model's verdict (ContentRevetService).
 * A flag sends an item to the vetting model first and is shown in the report.
 */
final class ContentQualityHeuristics
{
    /** Text about how the content was produced. Any hit is a strong signal. */
    private const META_PATTERNS = [
        'draft_generation'   => '/\bdraft[_\s-]generation\b/i',
        'intent'             => '/\b(?:the|an?|its)\s+intent\s+["\'\x{2018}\x{201C}]?[a-z]+_[a-z_]+|["\']intent["\']\s*:/iu',
        'grounding_context'  => '/\bgrounding\s+context\b/i',
        'content_pipeline'   => '/\bcontent\s+pipeline\b/i',
        'as_an_ai'           => '/\bas\s+an\s+ai\b|\blanguage\s+model\b/i',
        'task_type'          => '/\btask_type\b|\bcontent_type\b|\bblog_post\b|\bbody_html\b/i',
        'placeholder'        => '/\blorem\s+ipsum\b|\bmock\s+generated\b|\buntitled\s+draft\b|\[(?:insert|placeholder)[^\]]*\]/i',
        'prompt_echo'        => '/\b(?:system\s+prompt|prompt\s+template|the\s+provided\s+context|based\s+on\s+the\s+(?:grounding|provided)\s+(?:context|data))\b/i',
    ];

    /** Generic marketing filler that never belongs in an AICOUNTLY tax/accounting article. */
    private const FILLER_PATTERNS = [
        '/\bdigital\s+content\s+creation\b/i',
        '/\bcontent\s+creation\s+(?:process|strategy|landscape)\b/i',
        "/\\bin\\s+today(?:'|\\x{2019})?s\\s+fast[-\\s]paced\\s+(?:digital\\s+)?(?:world|landscape)\\b/iu",
        '/\\b(?:marketing\\s+)?content\\s+generation\\b/i',
        '/\\bunlocking\\s+marketing\\s+potential\\b/i',
        '/\\b(?:effective|impactful|engaging|compelling)\\s+marketing\\s+content\\b/i',
        '/\bunlock\s+the\s+(?:full\s+)?potential\b/i',
    ];

    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how', 'in', 'into', 'is', 'it', 'its',
        'of', 'on', 'or', 'the', 'to', 'what', 'when', 'why', 'with', 'your', 'you', 'our', 'guide', 'basics',
        'explained', 'complete', 'simple', 'easy', 'best', 'top', 'tips', 'step', 'steps', 'checklist', 'small',
        'growing', 'businesses', 'business', 'companies', 'company', 'indian', 'india', 'without', 'common',
        'mistakes', 'new', 'vs', 'do', 'does', 'can', 'should', 'need', 'know', 'all', 'about',
    ];

    /**
     * @return array{suspicious: bool, flags: list<string>, title_coverage: ?float}
     */
    public static function assess(string $title, string $summary, string $bodyHtml): array
    {
        $body  = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $text  = $summary . ' ' . $body;
        $flags = [];

        foreach (self::META_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $flags[] = 'meta_text:' . $name;
            }
        }
        foreach (self::FILLER_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $flags[] = 'generic_filler';
                break;
            }
        }

        $words = str_word_count($body);
        if ($words < 150) {
            $flags[] = 'short_body:' . $words;
        }

        // Acronyms in the title (GST, TDS, ITR, MCA …) must appear in the body.
        if (preg_match_all('/\b[A-Z]{2,6}\b/', $title, $m) > 0) {
            foreach (array_unique($m[0]) as $acronym) {
                if (stripos($body, $acronym) === false) {
                    $flags[] = 'title_term_missing:' . $acronym;
                }
            }
        }

        $coverage = self::titleCoverage($title, $body);
        if ($coverage !== null && $coverage < 0.34) {
            $flags[] = 'title_not_reflected_in_body';
        }

        return ['suspicious' => $flags !== [], 'flags' => array_values(array_unique($flags)), 'title_coverage' => $coverage];
    }

    /** True when a flag alone is strong evidence the text is not an article. */
    public static function isStrong(array $flags): bool
    {
        foreach ($flags as $flag) {
            if (str_starts_with($flag, 'meta_text:') || $flag === 'generic_filler') {
                return true;
            }
        }

        return false;
    }

    /**
     * Share of the title's significant words (a 5-letter prefix match, so
     * "filing" finds "file"/"filed") that occur in the body; null when the
     * title has fewer than two significant words.
     */
    public static function titleCoverage(string $title, string $body): ?float
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, static fn (string $t) => mb_strlen($t) >= 3 && ! in_array($t, self::STOPWORDS, true))));
        if (count($tokens) < 2) {
            return null;
        }

        $haystack = mb_strtolower($body);
        $hits = 0;
        foreach ($tokens as $token) {
            $stem = mb_substr($token, 0, 5);
            if (preg_match('/\b' . preg_quote($stem, '/') . '/u', $haystack) === 1) {
                $hits++;
            }
        }

        return round($hits / count($tokens), 2);
    }
}

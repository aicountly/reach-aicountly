<?php

declare(strict_types=1);

namespace App\Libraries\Publishing\Blog;

use App\Libraries\Ai\Generation\StructuredOutputCoercer;

/**
 * Last line of defence between a draft row and aicountly.com/blogs.
 *
 * The public listing renders whatever excerpt/body the payload carries, so a
 * placeholder body ("Untitled draft........") reaches readers as if it were a
 * real article. Generation-time coercion already refuses to invent bodies, but
 * nothing re-checked the row at publish time — rows that predate the coercer,
 * or that were hand-edited, walked straight through. This guard re-runs the
 * check on the exact text that would be published.
 *
 * Pure and side-effect free so both the readiness gates (advisory, shown in
 * the UI) and the payload builder (hard failure) can share it.
 */
class PublishableContentGuard
{
    /** Minimum publishable article length, overridable per environment. */
    public const DEFAULT_MIN_WORDS = 300;

    /** Minimum excerpt length before we treat it as unusable on the listing. */
    private const MIN_EXCERPT_CHARS = 40;

    /**
     * Placeholder shapes that are not caught by a straight stub comparison —
     * "Untitled draft........................", "TBD — write this", template
     * tokens left in the body, and so on.
     */
    private const PLACEHOLDER_PATTERNS = [
        '/^\s*(untitled|draft|placeholder|sample|test)\b[\s\p{P}]*$/iu',
        '/^\s*untitled\s+draft\b/iu',
        '/^\s*(tbd|todo|n\/?a|coming soon|content pending|lorem ipsum)\b/iu',
        '/\{\{\s*[a-z0-9_.]+\s*\}\}/i',
        '/\[(insert|your|placeholder)[^\]]*\]/i',
    ];

    /**
     * Assess the body that would be published.
     *
     * @return array{publishable: bool, reasons: list<string>, words: int}
     */
    public function assessBody(?string $bodyHtml, ?string $title = null, ?int $minWords = null): array
    {
        $minWords = $minWords ?? self::minWords();
        $text     = self::toPlainText((string) $bodyHtml);
        $words    = self::countWords($text);
        $reasons  = [];

        if ($text === '') {
            return ['publishable' => false, 'reasons' => ['Body is empty'], 'words' => 0];
        }

        if (StructuredOutputCoercer::isStubBody((string) $bodyHtml, null, $text, $title)) {
            $reasons[] = 'Body is a placeholder/stub, not a real article';
        } elseif (self::isPlaceholderText($text)) {
            $reasons[] = 'Body starts with placeholder text (e.g. "Untitled draft")';
        }

        if ($words < $minWords) {
            $reasons[] = "Body is too short to publish: {$words} words, minimum {$minWords}";
        }

        if (self::isMostlyPunctuation($text)) {
            $reasons[] = 'Body is mostly punctuation/filler characters';
        }

        return [
            'publishable' => $reasons === [],
            'reasons'     => $reasons,
            'words'       => $words,
        ];
    }

    /**
     * True when a candidate excerpt/summary must not be shown on the listing.
     */
    public function isUnusableExcerpt(?string $excerpt): bool
    {
        $text = self::toPlainText((string) $excerpt);

        if ($text === '' || mb_strlen($text) < self::MIN_EXCERPT_CHARS) {
            return true;
        }

        return self::isPlaceholderText($text) || self::isMostlyPunctuation($text);
    }

    /**
     * Pick the first candidate that reads like a real excerpt.
     *
     * @param list<string|null> $candidates
     */
    public function firstUsableExcerpt(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '' && ! $this->isUnusableExcerpt($text)) {
                return $text;
            }
        }

        return '';
    }

    public static function minWords(): int
    {
        return max(1, (int) env('BLOG_MIN_PUBLISH_WORDS', self::DEFAULT_MIN_WORDS));
    }

    /**
     * Placeholder prose detection — deliberately anchored so an article that
     * merely mentions "TBD" mid-sentence is not rejected.
     */
    public static function isPlaceholderText(string $text): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($normalized === '') {
            return true;
        }

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        // "Untitled draft ........." — a short lead followed by a run of dots
        // or dashes is a stub regardless of the total character count.
        if (preg_match('/^(.{0,60}?)[\s]*[.\-–—_]{6,}/u', $normalized, $m) === 1) {
            if (self::countWords($m[1]) < 8) {
                return true;
            }
        }

        return false;
    }

    /** Strip markup and normalise whitespace, keeping word boundaries intact. */
    public static function toPlainText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Block tags must become spaces or "</h2><p>" would fuse two words.
        $spaced = preg_replace('/<(br|\/p|\/h[1-6]|\/li|\/div|\/tr|\/td|\/blockquote)[^>]*>/i', ' ', $html) ?? $html;
        $text   = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** Unicode-aware word count (str_word_count drops non-ASCII words). */
    public static function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        // \p{M} keeps Devanagari (and other) combining marks attached to their
        // base letter — without it "जीएसटी" counts as several words.
        $words = preg_split('/[^\p{L}\p{M}\p{N}\'’-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }

    private static function isMostlyPunctuation(string $text): bool
    {
        $letters = preg_match_all('/[\p{L}\p{N}]/u', $text);
        if ($letters === false) {
            return false;
        }

        $total = mb_strlen($text);

        return $total > 0 && ($letters / $total) < 0.5;
    }
}

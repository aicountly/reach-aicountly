<?php

namespace App\Libraries\Content;

/**
 * Single source of truth for turning a title into a URL slug.
 *
 * Every slug builder in the codebase used to inline the same three lines, and
 * two of them inlined them in the wrong order — see slug() for why that
 * matters. Keeping one implementation here means a title can only ever
 * produce one slug, whichever path created the content item.
 */
final class SlugBuilder
{
    /**
     * Normalise a title into a URL slug.
     *
     * Case folding MUST happen before the character filter. `[^a-z0-9]` is
     * case-sensitive, so filtering first treats every capital letter as
     * punctuation and replaces it with a separator: "TDS Compliance Basics
     * for Growing Companies" collapsed to "ompliance-asics-for-rowing-
     * ompanies" — acronyms deleted outright, the first letter eaten off every
     * capitalised word. Those slugs reach the public site as /blogs/<slug>.
     */
    public static function slug(string $title, string $fallback = ''): string
    {
        $text = mb_strtolower(trim($title), 'UTF-8');
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';

        return trim($text, '-') ?: $fallback;
    }

    /**
     * Reproduce the pre-fix (corrupting) slug for a title.
     *
     * The repair pass uses this to tell a slug the broken builder generated
     * from one a human deliberately typed: only a stored slug that is exactly
     * what the old code would have produced is safe to rewrite.
     */
    public static function legacyCorruptedSlug(string $title): string
    {
        // Deliberately the old, wrong order — do not "fix" this one.
        $base = strtolower((string) preg_replace('/[^a-z0-9]+/', '-', trim($title)));

        return trim($base, '-');
    }

    /**
     * True when $slug looks like corruption of $title rather than a choice.
     */
    public static function isCorrupted(string $slug, string $title): bool
    {
        $slug = trim($slug);
        if ($slug === '' || trim($title) === '') {
            return false;
        }

        $corrupted = self::legacyCorruptedSlug($title);

        return $corrupted !== '' && $slug === $corrupted && $slug !== self::slug($title);
    }
}

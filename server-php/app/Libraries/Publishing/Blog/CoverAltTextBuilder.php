<?php

declare(strict_types=1);

namespace App\Libraries\Publishing\Blog;

/**
 * Alt text for blog cover images.
 *
 * Screen readers and the readiness gate both need this to describe the
 * article, not the file — "Illustration: cover image" is technically present
 * but useless. Deterministic, no AI.
 */
class CoverAltTextBuilder
{
    private const MAX_LENGTH = 240;

    public static function build(string $title, string $category = ''): string
    {
        $title    = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $category = trim(preg_replace('/\s+/u', ' ', $category) ?? $category);

        if ($title === '') {
            return $category !== ''
                ? 'Editorial illustration for an article on ' . mb_strtolower($category)
                : 'Editorial illustration for an AICOUNTLY blog article';
        }

        $alt = 'Editorial illustration for the article “' . $title . '”';
        if ($category !== '') {
            $alt .= ' on ' . mb_strtolower($category);
        }

        return mb_substr($alt, 0, self::MAX_LENGTH);
    }
}

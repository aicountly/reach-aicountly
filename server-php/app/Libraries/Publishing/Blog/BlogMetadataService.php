<?php

namespace App\Libraries\Publishing\Blog;

/**
 * Phase 4 — Blog metadata derivation (reading time, excerpt, author/reviewer resolution).
 */
class BlogMetadataService
{
    private const WORDS_PER_MINUTE = 200;

    /**
     * Estimate reading time in minutes from HTML body.
     */
    public function estimateReadingTime(string $bodyHtml): int
    {
        $words = PublishableContentGuard::countWords(PublishableContentGuard::toPlainText($bodyHtml));

        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    /**
     * Derive a plain-text excerpt from HTML body.
     *
     * The listing card shows this verbatim, so it must read as prose: block
     * tags become word boundaries (otherwise "</h2><p>" fuses two words),
     * leading headings are skipped in favour of the first real paragraph, and
     * truncation is multibyte-safe.
     */
    public function deriveExcerpt(string $bodyHtml, int $maxLength = 200): string
    {
        $text = PublishableContentGuard::toPlainText($this->preferFirstParagraph($bodyHtml));
        if ($text === '') {
            return '';
        }

        // Collapse decorative punctuation runs ("......") that would otherwise
        // eat the whole excerpt.
        $text = trim(preg_replace('/([.\-–—_])\1{3,}/u', '', $text) ?? $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');

        $cut = $lastSpace !== false ? mb_substr($truncated, 0, $lastSpace) : $truncated;

        return rtrim($cut, " \t\n\r\0\x0B,;:.-") . '…';
    }

    /**
     * Prefer the first substantive <p> over a leading heading/table of
     * contents, falling back to the whole body when there is no usable one.
     */
    private function preferFirstParagraph(string $bodyHtml): string
    {
        if (! preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $bodyHtml, $matches)) {
            return $bodyHtml;
        }

        foreach ($matches[1] as $paragraph) {
            $plain = PublishableContentGuard::toPlainText($paragraph);
            if (PublishableContentGuard::countWords($plain) >= 12) {
                return $paragraph;
            }
        }

        return $bodyHtml;
    }

    /**
     * Resolve actor display name from actor ID.
     */
    public function resolveActorName(int $actorId): string
    {
        $db = \Config\Database::connect();
        $actor = $db->table('reach_actors')->where('id', $actorId)->get()->getRowArray();
        if (!$actor) {
            return '';
        }
        return $actor['display_name'] ?? $actor['email'] ?? '';
    }
}

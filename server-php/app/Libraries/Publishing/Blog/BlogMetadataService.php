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
        $text = strip_tags($bodyHtml);
        $words = str_word_count($text);
        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    /**
     * Derive a plain-text excerpt from HTML body.
     */
    public function deriveExcerpt(string $bodyHtml, int $maxLength = 200): string
    {
        $text = strip_tags($bodyHtml);
        $text = preg_replace('/\s+/', ' ', trim($text));
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        $truncated = substr($text, 0, $maxLength);
        $lastSpace = strrpos($truncated, ' ');
        return ($lastSpace !== false ? substr($truncated, 0, $lastSpace) : $truncated) . '…';
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

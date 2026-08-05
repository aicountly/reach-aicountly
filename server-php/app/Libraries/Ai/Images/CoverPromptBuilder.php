<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Images;

/**
 * Deterministic cover-image prompt for blog articles. No AI is involved in
 * building the prompt; providers never receive article body text, only the
 * title/summary/category, and the prompt bans embedded text so the cover
 * stays language-neutral and never contains hallucinated words.
 */
class CoverPromptBuilder
{
    public function build(string $title, ?string $summary = null, ?string $category = null): string
    {
        $title    = trim($title) !== '' ? trim($title) : 'business finance and compliance';
        $summary  = trim((string) $summary);
        $category = trim((string) $category);

        $subject = mb_substr($title, 0, 180);
        $context = $summary !== '' ? (' Context: ' . mb_substr($summary, 0, 240)) : '';
        $theme   = $category !== '' ? (' Theme: ' . $category . '.') : '';

        return 'Flat editorial illustration for a professional finance and accounting blog article about "'
            . $subject . '".' . $theme . $context
            . ' Style: modern minimal vector illustration, clean geometric shapes,'
            . ' calm green and white brand palette with soft accents, generous negative space,'
            . ' subtle Indian business setting where people appear.'
            . ' Composition: 3:2 landscape, single clear focal concept.'
            . ' Strictly no text, no words, no letters, no numbers, no logos, no watermarks.';
    }
}

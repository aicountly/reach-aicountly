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
    /**
     * @param string|null $sceneHint the content base's `cover_prompt` — a short
     *                               scene note ("two document stacks compared,
     *                               magnifying glass over a mismatch") that is a
     *                               fine internal hint and a poor prompt on its
     *                               own. Given one, it becomes the required
     *                               visual concept rather than being ignored.
     */
    public function build(string $title, ?string $summary = null, ?string $category = null, ?string $sceneHint = null, array $keyPoints = []): string
    {
        $title     = trim($title) !== '' ? trim($title) : 'business finance and compliance';
        $summary   = trim((string) $summary);
        // A category slug ("tds-tcs") reads as its label ("TDS/TCS").
        $category  = \App\Libraries\Blog\BlogTaxonomy::displayLabel($category);
        $sceneHint = trim((string) $sceneHint);

        $points = [];
        foreach ($keyPoints as $point) {
            $point = trim(preg_replace('/\s+/', ' ', strip_tags((string) $point)) ?? '');
            if ($point !== '' && count($points) < 4) {
                $points[] = mb_substr($point, 0, 80);
            }
        }

        $subject = mb_substr($title, 0, 180);
        $context = $summary !== '' ? (' Context: ' . mb_substr($summary, 0, 240)) : '';
        $theme   = $category !== '' ? (' Theme: ' . $category . '.') : '';
        $scene   = $sceneHint !== '' ? (' Depict: ' . rtrim(mb_substr($sceneHint, 0, 240), '. ') . '.') : '';
        $covers  = $points !== [] ? (' The article covers: ' . implode('; ', $points) . ' — show these as visual objects, not words.') : '';

        return 'Flat editorial illustration for a professional finance and accounting blog article about "'
            . $subject . '".' . $theme . $scene . $context . $covers
            . ' Style: modern minimal vector illustration, clean geometric shapes,'
            . ' calm green and white brand palette (AICOUNTLY greens #8cc854 and #1b7f00, navy #071a33 accents) with soft accents, generous negative space,'
            . ' subtle Indian business setting where people appear.'
            . ' Composition: 3:2 landscape, single clear focal concept, keep the bottom-right corner calm (a logo is placed there afterwards).'
            . ' Strictly no text, no words, no letters, no numbers, no logos, no watermarks.';
    }

    /**
     * Section headings of an article body — the "key points" a cover can
     * show. Markdown "## " headings first, then HTML <h2>/<h3>.
     *
     * @return list<string>
     */
    public static function keyPointsFrom(?string $markdown, ?string $html = null): array
    {
        $out = [];
        if (is_string($markdown) && $markdown !== '' && preg_match_all('/^#{2,3}\s+(.+)$/m', $markdown, $m)) {
            $out = $m[1];
        } elseif (is_string($html) && $html !== '' && preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', $html, $m)) {
            $out = $m[1];
        }
        $out = array_values(array_filter(array_map(
            static fn ($h) => trim(html_entity_decode(strip_tags((string) $h), ENT_QUOTES)),
            $out,
        ), static fn (string $h) => $h !== '' && ! preg_match('/^(faq|frequently asked|conclusion|summary|key takeaways|references|sources)/i', $h)));

        return array_slice($out, 0, 4);
    }

    /**
     * A prompt an operator can paste into ChatGPT or Gemini and get one usable
     * cover back.
     *
     * The console used to hand over the bare scene hint, which reads as a
     * fragment rather than an instruction: no subject, no output format, no
     * count, and nothing stopping the model returning four variations with
     * captions burnt into them. This states the job first and the constraints
     * last, which is the order these models follow most reliably.
     */
    public function buildForOperator(string $title, ?string $sceneHint = null, ?string $category = null): string
    {
        return 'Generate one landscape image, 3:2 aspect ratio (1536x1024), for use as a blog cover. '
            . $this->build($title, null, $category, $sceneHint)
            . ' Return a single image, not a set of variations, and do not add captions,'
            . ' titles or any lettering anywhere in the artwork.';
    }
}

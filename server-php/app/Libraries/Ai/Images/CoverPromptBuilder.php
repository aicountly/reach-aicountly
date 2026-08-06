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
    public function build(string $title, ?string $summary = null, ?string $category = null, ?string $sceneHint = null): string
    {
        $title     = trim($title) !== '' ? trim($title) : 'business finance and compliance';
        $summary   = trim((string) $summary);
        $category  = trim((string) $category);
        $sceneHint = trim((string) $sceneHint);

        $subject = mb_substr($title, 0, 180);
        $context = $summary !== '' ? (' Context: ' . mb_substr($summary, 0, 240)) : '';
        $theme   = $category !== '' ? (' Theme: ' . $category . '.') : '';
        $scene   = $sceneHint !== '' ? (' Depict: ' . rtrim(mb_substr($sceneHint, 0, 240), '. ') . '.') : '';

        return 'Flat editorial illustration for a professional finance and accounting blog article about "'
            . $subject . '".' . $theme . $scene . $context
            . ' Style: modern minimal vector illustration, clean geometric shapes,'
            . ' calm green and white brand palette with soft accents, generous negative space,'
            . ' subtle Indian business setting where people appear.'
            . ' Composition: 3:2 landscape, single clear focal concept.'
            . ' Strictly no text, no words, no letters, no numbers, no logos, no watermarks.';
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

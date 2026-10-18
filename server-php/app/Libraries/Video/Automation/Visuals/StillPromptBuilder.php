<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Visuals;

/**
 * Prompts for generated video stills and the YouTube thumbnail, built from
 * the vetted script — the video's title and topic plus what the scene
 * actually says — so a still illustrates that scene, not "finance" in general.
 *
 * The routine's own visual note (visual.url_or_prompt) is kept as the scene to
 * depict. Brand style (navy / blue / green palette, no text, no logos) is
 * appended by OpenAiStillSource::STYLE; the AICOUNTLY logo is added by the
 * render template (corner mark on every scene) and composited onto the
 * thumbnail, never drawn by the image model.
 */
final class StillPromptBuilder
{
    /**
     * @param array<string, mixed> $script vetted, normalised script
     * @param array<string, mixed> $scene  one of $script['scenes']
     */
    public static function forScene(array $script, array $scene): string
    {
        $title   = self::clip((string) ($script['title'] ?? ''), 140);
        $topic   = self::topic($script);
        $voice   = self::clip((string) ($scene['voice_over_text'] ?? ''), 360);
        $onScr   = self::clip((string) ($scene['on_screen_text'] ?? ''), 90);
        $depict  = self::clip((string) ($scene['visual']['url_or_prompt'] ?? ''), 500);
        $format  = ($script['format'] ?? 'vlog') === 'short' ? 'vertical 9:16 frame' : 'landscape 16:9 frame';

        $parts = [
            sprintf('One still for a scene of the AICOUNTLY explainer video "%s"%s.', $title, $topic !== '' ? ' about ' . $topic : ''),
            $depict !== '' ? 'Depict: ' . rtrim($depict, '. ') . '.' : null,
            $voice !== '' ? 'The narrator says in this scene: "' . $voice . '" — illustrate exactly that idea.' : null,
            $onScr !== '' ? 'The scene\'s caption (added later, do not draw it): "' . $onScr . '".' : null,
            "Composition: {$format}, one clear focal concept, keep the top-right corner calm (the logo sits there).",
        ];

        return implode(' ', array_values(array_filter($parts, static fn ($p) => $p !== null)));
    }

    /** @param array<string, mixed> $script */
    public static function forThumbnail(array $script): string
    {
        $title  = self::clip((string) ($script['title'] ?? ''), 140);
        $topic  = self::topic($script);
        $depict = self::clip((string) ($script['thumbnail_prompt'] ?? ''), 600);

        return trim(sprintf('YouTube thumbnail for the AICOUNTLY video "%s"%s.', $title, $topic !== '' ? ' about ' . $topic : '')
            . ($depict !== '' ? ' Depict: ' . rtrim($depict, '. ') . '.' : '')
            . ' Bold, high-contrast single focal concept readable at small size; leave the bottom-right corner calm (the logo is placed there).');
    }

    /** First sentence of the description (the topic), product when there is no description. @param array<string, mixed> $script */
    private static function topic(array $script): string
    {
        $description = trim((string) ($script['description'] ?? ''));
        if ($description !== '') {
            $first = preg_split('/(?<=[.!?])\s+/', $description, 2)[0] ?? $description;

            return self::clip($first, 200);
        }

        return trim((string) ($script['product_slug'] ?? ''));
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}

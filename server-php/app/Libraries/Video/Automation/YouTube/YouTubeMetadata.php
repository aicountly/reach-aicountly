<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\YouTube;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Render\TimelineBuilder;

/**
 * The videos resource (snippet + status) sent when the upload session opens.
 * The description carries chapters (vlogs), the CTA, the site link and an
 * honest note that the narration is an AI voice; the upload declares
 * containsSyntheticMedia accordingly.
 */
final class YouTubeMetadata
{
    public const DESCRIPTION_MAX_BYTES = 5000;

    /**
     * @param array<string, mixed> $item  pipeline item (script_json decoded)
     * @param array<string, mixed> $plan  render_plan_json: duration, scene_starts
     * @return array<string, mixed>
     */
    public static function resource(array $item, array $plan): array
    {
        $script = (array) $item['script_json'];
        $short  = $script['format'] === 'short';

        $title = (string) $script['title'];
        if ($short && ! str_contains(strtolower($title), '#shorts') && mb_strlen($title) <= 92) {
            $title .= ' #Shorts';
        }

        $parts = [trim((string) $script['description'])];
        $chapters = TimelineBuilder::chapterLines((array) ($script['chapters'] ?? []), self::starts($plan), (float) ($plan['duration'] ?? 0));
        if ($chapters !== []) {
            $parts[] = "Chapters\n" . implode("\n", $chapters);
        }
        $parts[] = trim((string) $script['cta_text']) . "\nhttps://aicountly.com";
        $parts[] = 'Narrated with an AI voice. Product screens are from aicountly.com.';
        if ($short) {
            $parts[] = '#Shorts';
        }

        $privacy = strtolower(trim((string) ReachConfig::get('video.youtube.privacy')));
        if (! in_array($privacy, ['public', 'unlisted', 'private'], true)) {
            $privacy = 'private';
        }
        $category = preg_match('/^\d{1,3}$/', (string) ReachConfig::get('video.youtube.category_id')) ? (string) ReachConfig::get('video.youtube.category_id') : '28';

        return [
            'snippet' => [
                'title'                => mb_substr($title, 0, 100),
                'description'          => self::truncateBytes(implode("\n\n", $parts), self::DESCRIPTION_MAX_BYTES),
                'tags'                 => array_values((array) ($script['tags'] ?? [])),
                'categoryId'           => $category,
                'defaultLanguage'      => 'en',
                'defaultAudioLanguage' => 'en-IN',
            ],
            'status' => [
                'privacyStatus'           => $privacy,
                'selfDeclaredMadeForKids' => false,
                'embeddable'              => true,
                'license'                 => 'youtube',
                'containsSyntheticMedia'  => true,
            ],
        ];
    }

    public static function watchUrl(string $videoId, bool $short): string
    {
        return $short ? 'https://www.youtube.com/shorts/' . rawurlencode($videoId) : 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
    }

    /** @return array<int, float> */
    private static function starts(array $plan): array
    {
        $out = [];
        foreach ((array) ($plan['scene_starts'] ?? []) as $order => $t) {
            $out[(int) $order] = (float) $t;
        }
        return $out;
    }

    private static function truncateBytes(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_strcut($text, 0, $max - 3, 'UTF-8')) . '…';
    }
}

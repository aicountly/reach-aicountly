<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Render;

/**
 * Builds the Shotstack edit (timeline + output) for one pipeline item from
 * its vetted script and the stored voice-overs / visuals.
 *
 * Branded template (Aicountly colours from aicountly.com's aic-brand.css and
 * Montserrat; the official logo, video.brand.logo_url, default
 * https://aicountly.com/assests/images/ai-logo.png):
 *   - white canvas; intro card (logo + title, vlog only) and an end card
 *     (logo + CTA + aicountly.com) — in both 16:9 and 9:16
 *   - each scene: its screenshot / still full-frame (stills slowly zoom),
 *     or a title card (navy text on the brand's soft green panel), the
 *     voice-over clip, and the Aicountly corner mark (logo_badge_url: the
 *     logo on a white plate, legible over dark stills; the plain logo when
 *     no badge URL is given)
 *   - lower third from the scene's on_screen_text (blue bar, white text)
 *   - captions burned in from the voice-over (white on navy), the same cues
 *     that go to YouTube as a caption track
 *   - optional background music, ducked low under the voice
 *
 * Tracks are listed top layer first, as Shotstack composites them.
 */
final class TimelineBuilder
{
    public const NAVY  = '#071a33';
    public const BLUE  = '#145490';
    public const GREEN = '#1b7f00';
    public const WHITE = '#ffffff';
    public const GREEN_SOFT = '#eaf8e6';

    /** The official logo on aicountly.com (assests/images/ai-logo.png). */
    public const DEFAULT_LOGO_URL = 'https://aicountly.com/assests/images/ai-logo.png';

    public const FONT_DISPLAY = 'Montserrat ExtraBold';
    public const FONT_TEXT    = 'Montserrat SemiBold';

    private const LAYOUT = [
        'vlog' => [
            'w' => 1920, 'h' => 1080, 'intro' => 3.5, 'outro' => 3.5,
            'caption' => ['w' => 1600, 'h' => 120, 'size' => 42, 'position' => 'bottom', 'y' => 0.04],
            'lower'   => ['w' => 1000, 'h' => 84, 'size' => 38, 'position' => 'bottomLeft', 'x' => 0.03, 'y' => 0.17],
            'card'    => ['w' => 1600, 'h' => 560, 'size' => 66],
            'logo'    => ['scale' => 0.32, 'card_scale' => 0.75, 'badge_scale' => 0.6],
            'shot_fit' => 'cover',
        ],
        'short' => [
            'w' => 1080, 'h' => 1920, 'intro' => 0.0, 'outro' => 3.0,
            'caption' => ['w' => 960, 'h' => 230, 'size' => 54, 'position' => 'center', 'y' => -0.24],
            'lower'   => ['w' => 980, 'h' => 190, 'size' => 50, 'position' => 'top', 'x' => 0.0, 'y' => -0.09],
            'card'    => ['w' => 940, 'h' => 900, 'size' => 76],
            'logo'    => ['scale' => 0.42, 'card_scale' => 0.9, 'badge_scale' => 0.7],
            'shot_fit' => 'contain',
        ],
    ];

    /** Seconds of silence after each voice-over before the next scene. */
    public const SCENE_PAD = 0.4;
    public const MIN_SCENE = 2.0;

    /**
     * @param array<string, mixed> $script  normalised, vetted script
     * @param list<array{order:int, voice_over_text:string, on_screen_text:string, duration_hint_secs:float,
     *                   visual_kind:string, image_url:?string, image_source:?string,
     *                   audio_url:?string, audio_secs:?float}> $scenes
     * @param array{logo_url?:?string, logo_badge_url?:?string, music_url?:?string, callback_url?:?string} $opts
     * @return array{edit: array<string, mixed>, duration: float, cues: list<array{start: float, end: float, text: string}>, scene_starts: array<int, float>}
     */
    public static function build(array $script, array $scenes, array $opts = []): array
    {
        $format = $script['format'] === 'short' ? 'short' : 'vlog';
        $L      = self::LAYOUT[$format];
        $logo   = self::httpsOrNull($opts['logo_url'] ?? null);
        $badge  = self::httpsOrNull($opts['logo_badge_url'] ?? null);

        $tracks = ['logo' => [], 'captions' => [], 'text' => [], 'visual' => [], 'audio' => []];
        $cues   = [];
        $starts = [];
        $t      = 0.0;

        // Intro card (vlog): logo above the title on the white canvas.
        if ($L['intro'] > 0) {
            if ($logo !== null) {
                $tracks['logo'][] = self::clip(['type' => 'image', 'src' => $logo], 0, $L['intro'], ['fit' => 'none', 'scale' => $L['logo']['card_scale'], 'position' => 'center', 'offset' => ['x' => 0, 'y' => 0.2], 'transition' => ['in' => 'fade']]);
            }
            $tracks['text'][] = self::clip(self::text($script['title'], $L['card']['w'], 360, self::FONT_DISPLAY, self::NAVY, $L['card']['size']), 0, $L['intro'], ['position' => 'center', 'offset' => ['x' => 0, 'y' => -0.12], 'transition' => ['in' => 'fade', 'out' => 'fade']]);
            $t = $L['intro'];
        }

        foreach ($scenes as $scene) {
            $audio = $scene['audio_url'] !== null && $scene['audio_secs'] !== null ? (float) $scene['audio_secs'] : null;
            $len   = round(max(self::MIN_SCENE, $audio !== null ? $audio + self::SCENE_PAD : (float) $scene['duration_hint_secs']), 3);
            $starts[(int) $scene['order']] = round($t, 3);

            if ($scene['visual_kind'] === 'image' && $scene['image_url'] !== null) {
                $still = $scene['image_source'] === 'dalle';
                $tracks['visual'][] = self::clip(['type' => 'image', 'src' => $scene['image_url']], $t, $len, array_filter([
                    'fit'        => $still ? 'cover' : $L['shot_fit'],
                    'effect'     => $still ? 'zoomInSlow' : null,
                    'transition' => ['in' => 'fade', 'out' => 'fade'],
                ]));
                if ($scene['on_screen_text'] !== '') {
                    $lower = $L['lower'];
                    $tracks['text'][] = self::clip(
                        self::text($scene['on_screen_text'], $lower['w'], $lower['h'], self::FONT_TEXT, self::WHITE, $lower['size'], self::BLUE, 0.92, $format === 'short' ? 'center' : 'left'),
                        $t + 0.3,
                        round(min($len - 0.3, 6.0), 3),
                        ['position' => $lower['position'], 'offset' => ['x' => $lower['x'], 'y' => $lower['y']], 'transition' => ['in' => 'slideRight', 'out' => 'fade']],
                    );
                }
            } else {
                // Title card: the scene's on-screen text, navy on the brand's
                // soft green panel over the white canvas.
                $tracks['text'][] = self::clip(self::text($scene['on_screen_text'] !== '' ? $scene['on_screen_text'] : $script['title'], $L['card']['w'], $L['card']['h'], self::FONT_DISPLAY, self::NAVY, $L['card']['size'], self::GREEN_SOFT, 1.0), $t, $len, ['position' => 'center', 'transition' => ['in' => 'fade', 'out' => 'fade']]);
            }

            // Corner mark on every scene, both formats.
            if ($badge !== null) {
                $tracks['logo'][] = self::clip(['type' => 'image', 'src' => $badge], $t, $len, ['fit' => 'none', 'scale' => $L['logo']['badge_scale'], 'position' => 'topRight', 'offset' => ['x' => -0.02, 'y' => -0.03]]);
            } elseif ($logo !== null) {
                $tracks['logo'][] = self::clip(['type' => 'image', 'src' => $logo], $t, $len, ['fit' => 'none', 'scale' => $L['logo']['scale'], 'position' => 'topRight', 'offset' => ['x' => -0.02, 'y' => -0.03], 'opacity' => 0.95]);
            }

            if ($audio !== null) {
                $tracks['audio'][] = self::clip(['type' => 'audio', 'src' => $scene['audio_url'], 'volume' => 1], $t, round($audio, 3));
                $cap = $L['caption'];
                foreach (CaptionBuilder::cues($scene['voice_over_text'], $t, $audio) as $cue) {
                    $cues[] = $cue;
                    $tracks['captions'][] = self::clip(
                        self::text($cue['text'], $cap['w'], $cap['h'], self::FONT_TEXT, self::WHITE, $cap['size'], self::NAVY, 0.78),
                        $cue['start'],
                        round($cue['end'] - $cue['start'], 3),
                        ['position' => $cap['position'], 'offset' => ['x' => 0, 'y' => $cap['y']]],
                    );
                }
            }
            $t += $len;
        }

        // End card: logo, CTA and the site.
        $outro = $L['outro'];
        if ($logo !== null) {
            $tracks['logo'][] = self::clip(['type' => 'image', 'src' => $logo], $t, $outro, ['fit' => 'none', 'scale' => $L['logo']['card_scale'], 'position' => 'center', 'offset' => ['x' => 0, 'y' => 0.22], 'transition' => ['in' => 'fade']]);
        }
        $tracks['text'][] = self::clip(self::text($script['cta_text'], $L['card']['w'], 300, self::FONT_DISPLAY, self::NAVY, (int) round($L['card']['size'] * 0.8)), $t, $outro, ['position' => 'center', 'offset' => ['x' => 0, 'y' => -0.06], 'transition' => ['in' => 'fade', 'out' => 'fade']]);
        $tracks['text'][] = self::clip(self::text('aicountly.com', 800, 90, self::FONT_TEXT, self::GREEN, (int) round($L['card']['size'] * 0.6)), $t + 0.4, $outro - 0.4, ['position' => 'center', 'offset' => ['x' => 0, 'y' => -0.24], 'transition' => ['in' => 'fade', 'out' => 'fade']]);
        $t += $outro;

        $timeline = [
            'background' => self::WHITE,
            'tracks'     => array_values(array_map(static fn (array $clips) => ['clips' => $clips], array_filter($tracks, static fn ($c) => $c !== []))),
        ];
        $music = self::httpsOrNull($opts['music_url'] ?? null);
        if ($music !== null) {
            $timeline['soundtrack'] = ['src' => $music, 'effect' => 'fadeInFadeOut', 'volume' => 0.12];
        }

        $edit = [
            'timeline' => $timeline,
            'output'   => [
                'format' => 'mp4',
                'fps'    => 25,
                'size'   => ['width' => $L['w'], 'height' => $L['h']],
                'poster' => ['capture' => min(5.0, round($t / 2, 1))],
            ],
        ];
        $callback = self::httpsOrNull($opts['callback_url'] ?? null);
        if ($callback !== null) {
            $edit['callback'] = $callback;
        }

        return ['edit' => $edit, 'duration' => round($t, 3), 'cues' => $cues, 'scene_starts' => $starts];
    }

    /**
     * YouTube chapter lines ("0:00 Title") for the description, or [] when the
     * chapters would not satisfy YouTube's rules (first at 0:00, at least
     * three, each at least 10 s long).
     *
     * @param list<array{title: string, scene_order: int}> $chapters
     * @param array<int, float> $sceneStarts
     * @return list<string>
     */
    public static function chapterLines(array $chapters, array $sceneStarts, float $duration): array
    {
        $marks = [];
        foreach ($chapters as $i => $chapter) {
            $start = $sceneStarts[$chapter['scene_order']] ?? null;
            if ($start === null) {
                return [];
            }
            $marks[] = ['t' => $i === 0 ? 0.0 : $start, 'title' => $chapter['title']];
        }
        if (count($marks) < 3) {
            return [];
        }
        foreach ($marks as $i => $mark) {
            $end = $marks[$i + 1]['t'] ?? $duration;
            if ($end - $mark['t'] < 10) {
                return [];
            }
        }
        return array_map(static function (array $m): string {
            $s = (int) floor($m['t']);
            $stamp = $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
            return $stamp . ' ' . $m['title'];
        }, $marks);
    }

    /** @return array<string, mixed> */
    private static function text(string $text, int $w, int $h, string $font, string $color, int $size, ?string $bg = null, float $bgOpacity = 1.0, string $align = 'center'): array
    {
        $asset = [
            'type'      => 'text',
            'text'      => $text,
            'width'     => $w,
            'height'    => $h,
            'font'      => ['family' => $font, 'color' => $color, 'size' => $size, 'lineHeight' => 1.15],
            'alignment' => ['horizontal' => $align, 'vertical' => 'center'],
        ];
        if ($bg !== null) {
            $asset['background'] = ['color' => $bg, 'opacity' => $bgOpacity];
        }
        return $asset;
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function clip(array $asset, float $start, float $length, array $extra = []): array
    {
        return ['asset' => $asset, 'start' => round($start, 3), 'length' => round(max(0.1, $length), 3)] + $extra;
    }

    /** The configured logo URL when it is https, otherwise the official one — a video never renders unbranded for want of a setting. */
    public static function logoUrl(?string $configured): string
    {
        return self::httpsOrNull(trim((string) $configured)) ?? self::DEFAULT_LOGO_URL;
    }

    private static function httpsOrNull(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}

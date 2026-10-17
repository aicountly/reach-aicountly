<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Render;

/**
 * Splits voice-over text into short caption cues timed across the spoken
 * audio (proportional to text length — there is no forced alignment on
 * shared hosting), and renders them as SRT for captions.insert. The same
 * cues are burned into the video by the timeline.
 */
final class CaptionBuilder
{
    public const MAX_WORDS = 7;
    public const MAX_CHARS = 42;

    /** @return list<array{start: float, end: float, text: string}> */
    public static function cues(string $text, float $start, float $duration): array
    {
        $chunks = self::chunks($text);
        if ($chunks === [] || $duration <= 0) {
            return [];
        }
        $weights = array_map(static fn (string $c) => mb_strlen($c) + 2, $chunks);
        $sum     = array_sum($weights);
        $cues    = [];
        $t       = $start;
        foreach ($chunks as $i => $chunk) {
            $len    = $duration * $weights[$i] / $sum;
            $cues[] = ['start' => round($t, 3), 'end' => round($t + $len, 3), 'text' => $chunk];
            $t     += $len;
        }
        return $cues;
    }

    /** @return list<string> */
    public static function chunks(string $text): array
    {
        $words  = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        $cur    = [];
        foreach ($words as $word) {
            $candidate = trim(implode(' ', [...$cur, $word]));
            if ($cur !== [] && (count($cur) >= self::MAX_WORDS || mb_strlen($candidate) > self::MAX_CHARS)) {
                $chunks[] = implode(' ', $cur);
                $cur      = [];
            }
            $cur[] = $word;
            // Prefer to break after punctuation once a cue has a few words.
            if (count($cur) >= 3 && preg_match('/[.,;:!?…]$/u', $word)) {
                $chunks[] = implode(' ', $cur);
                $cur      = [];
            }
        }
        if ($cur !== []) {
            $chunks[] = implode(' ', $cur);
        }
        return $chunks;
    }

    /** @param list<array{start: float, end: float, text: string}> $cues */
    public static function srt(array $cues): string
    {
        $out = [];
        foreach (array_values($cues) as $i => $cue) {
            $out[] = ($i + 1) . "\n" . self::stamp($cue['start']) . ' --> ' . self::stamp($cue['end']) . "\n" . $cue['text'] . "\n";
        }
        return implode("\n", $out);
    }

    private static function stamp(float $secs): string
    {
        $ms = (int) round($secs * 1000);
        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3_600_000), intdiv($ms % 3_600_000, 60_000), intdiv($ms % 60_000, 1000), $ms % 1000);
    }
}

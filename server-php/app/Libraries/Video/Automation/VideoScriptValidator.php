<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

/**
 * Validates and normalises a routine's video script (POST
 * v1/automation/video-scripts). Anything the renderer or YouTube would choke
 * on is refused here with a 422-able message, so a bad script never reaches
 * the vetting model or the pipeline.
 */
final class VideoScriptValidator
{
    public const FORMATS      = ['vlog', 'short'];
    public const VISUAL_TYPES = ['screenshot', 'image', 'title_card', 'stock'];

    /** Spoken words per second used to estimate length before the voice-over exists. */
    public const WORDS_PER_SECOND = 2.5;

    public const VLOG_MIN_SECS  = 100;
    public const VLOG_MAX_SECS  = 380;
    public const SHORT_MAX_SECS = 60;
    /** Seconds of intro/outro cards the timeline adds around the scenes. */
    public const VLOG_FRAME_SECS  = 7.0;
    public const SHORT_FRAME_SECS = 3.0;

    /** Hosts a screenshot scene may point at (our public site and app subdomains). */
    public static function screenshotHostAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return $host === 'aicountly.com' || str_ends_with($host, '.aicountly.com');
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed> normalised script
     * @throws \InvalidArgumentException
     */
    public static function normalize(array $in): array
    {
        $key = trim((string) ($in['external_key'] ?? ''));
        if ($key === '' || strlen($key) > 191 || ! preg_match('/^[A-Za-z0-9._:\/\-]+$/', $key)) {
            throw new \InvalidArgumentException('external_key is required (1–191 chars: letters, digits, . _ : / -).');
        }
        $format = strtolower(trim((string) ($in['format'] ?? '')));
        if (! in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('format must be "vlog" or "short".');
        }

        $title = self::clean((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 100) {
            throw new \InvalidArgumentException('title is required and must be at most 100 characters.');
        }
        $description = self::clean((string) ($in['description'] ?? ''), true);
        if ($description === '' || mb_strlen($description) > 4000) {
            throw new \InvalidArgumentException('description is required and must be at most 4000 characters.');
        }
        $cta = self::clean((string) ($in['cta_text'] ?? ''));
        if ($cta === '' || mb_strlen($cta) > 140) {
            throw new \InvalidArgumentException('cta_text is required (at most 140 characters).');
        }
        $thumb = self::clean((string) ($in['thumbnail_prompt'] ?? ''), true);
        if (mb_strlen($thumb) > 1500) {
            throw new \InvalidArgumentException('thumbnail_prompt must be at most 1500 characters.');
        }
        $product = trim((string) ($in['product_slug'] ?? ''));
        if ($product !== '' && ! preg_match('/^[a-z0-9_\-]{2,80}$/', $product)) {
            throw new \InvalidArgumentException('product_slug must be lowercase letters, digits, _ or -.');
        }

        $tags = self::tags($in['tags'] ?? []);
        $scenes = self::scenes($in['scenes'] ?? null, $format);
        $chapters = self::chapters($in['chapters'] ?? [], $scenes, $format);

        $estimate = self::estimateSeconds($scenes) + ($format === 'vlog' ? self::VLOG_FRAME_SECS : self::SHORT_FRAME_SECS);
        if ($format === 'short' && $estimate > self::SHORT_MAX_SECS) {
            throw new \InvalidArgumentException(sprintf('A Short must run at most 60 s; this script reads as about %d s (≈ %d words at %.1f words/s, plus a 3 s end card). Cut the voice-over.', (int) ceil($estimate), self::wordCount($scenes), self::WORDS_PER_SECOND));
        }
        if ($format === 'vlog' && ($estimate < self::VLOG_MIN_SECS || $estimate > self::VLOG_MAX_SECS)) {
            throw new \InvalidArgumentException(sprintf('A vlog must run 2–6 minutes; this script reads as about %d s (≈ %d words). Aim for 300–850 words of voice-over.', (int) round($estimate), self::wordCount($scenes)));
        }

        return [
            'external_key'     => $key,
            'format'           => $format,
            'title'            => $title,
            'description'      => $description,
            'tags'             => $tags,
            'chapters'         => $chapters,
            'scenes'           => $scenes,
            'cta_text'         => $cta,
            'thumbnail_prompt' => $thumb,
            'product_slug'     => $product !== '' ? $product : null,
            'estimated_secs'   => (int) round($estimate),
        ];
    }

    /**
     * The fields the vetting model judges (and may correct).
     *
     * @param array<string, mixed> $script
     * @return array<string, mixed>
     */
    public static function vettingDraft(array $script): array
    {
        return [
            'format'           => $script['format'],
            'title'            => $script['title'],
            'description'      => $script['description'],
            'tags'             => $script['tags'],
            'chapters'         => $script['chapters'],
            'scenes'           => $script['scenes'],
            'cta_text'         => $script['cta_text'],
            'thumbnail_prompt' => $script['thumbnail_prompt'],
        ];
    }

    /**
     * Apply the vetting model's corrections to text fields only — visuals,
     * scene order and timing hints stay as the routine sent them — then
     * validate the result again.
     *
     * @param array<string, mixed> $script
     * @param array<string, mixed> $final
     * @return array<string, mixed>
     */
    public static function applyRevision(array $script, array $final): array
    {
        $next = $script;
        foreach (['title', 'description', 'cta_text', 'thumbnail_prompt'] as $field) {
            if (is_string($final[$field] ?? null) && trim($final[$field]) !== '') {
                $next[$field] = $final[$field];
            }
        }
        if (is_array($final['tags'] ?? null) && $final['tags'] !== []) {
            $next['tags'] = array_values(array_filter($final['tags'], 'is_string'));
        }
        $revisedScenes = [];
        foreach ((array) ($final['scenes'] ?? []) as $scene) {
            if (is_array($scene) && isset($scene['order'])) {
                $revisedScenes[(int) $scene['order']] = $scene;
            }
        }
        foreach ($next['scenes'] as $i => $scene) {
            $rev = $revisedScenes[(int) $scene['order']] ?? null;
            if ($rev === null) {
                continue;
            }
            foreach (['voice_over_text', 'on_screen_text'] as $field) {
                if (is_string($rev[$field] ?? null)) {
                    $next['scenes'][$i][$field] = $rev[$field];
                }
            }
        }
        foreach ($next['chapters'] as $i => $chapter) {
            $rev = $final['chapters'][$i] ?? null;
            if (is_array($rev) && is_string($rev['title'] ?? null) && trim($rev['title']) !== '') {
                $next['chapters'][$i]['title'] = $rev['title'];
            }
        }

        return self::normalize($next);
    }

    /** @param list<array<string, mixed>> $scenes */
    public static function estimateSeconds(array $scenes): float
    {
        $total = 0.0;
        foreach ($scenes as $scene) {
            $words  = self::words((string) $scene['voice_over_text']);
            $total += $words > 0 ? max($words / self::WORDS_PER_SECOND, 1.5) : (float) $scene['duration_hint_secs'];
        }
        return $total;
    }

    /** @param list<array<string, mixed>> $scenes */
    private static function wordCount(array $scenes): int
    {
        return array_sum(array_map(static fn ($s) => self::words((string) $s['voice_over_text']), $scenes));
    }

    private static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @return list<array<string, mixed>> */
    private static function scenes(mixed $raw, string $format): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new \InvalidArgumentException('scenes must be a non-empty array.');
        }
        [$min, $max] = $format === 'vlog' ? [3, 40] : [1, 12];
        if (count($raw) < $min || count($raw) > $max) {
            throw new \InvalidArgumentException("A {$format} needs {$min}–{$max} scenes.");
        }

        $out  = [];
        $seen = [];
        $voiced = 0;
        foreach (array_values($raw) as $i => $scene) {
            if (! is_array($scene)) {
                throw new \InvalidArgumentException("scenes[{$i}] must be an object.");
            }
            $order = isset($scene['order']) && is_numeric($scene['order']) ? (int) $scene['order'] : $i + 1;
            if ($order < 1 || isset($seen[$order])) {
                throw new \InvalidArgumentException("scenes[{$i}].order must be a unique positive integer.");
            }
            $seen[$order] = true;

            $voice = self::clean((string) ($scene['voice_over_text'] ?? ''), true);
            if (mb_strlen($voice) > 1500) {
                throw new \InvalidArgumentException("scenes[{$i}].voice_over_text must be at most 1500 characters.");
            }
            $voiced += $voice !== '' ? 1 : 0;
            $onScreen = self::clean((string) ($scene['on_screen_text'] ?? ''));
            if (mb_strlen($onScreen) > 90) {
                throw new \InvalidArgumentException("scenes[{$i}].on_screen_text must be at most 90 characters.");
            }
            $hint = isset($scene['duration_hint_secs']) && is_numeric($scene['duration_hint_secs']) ? (float) $scene['duration_hint_secs'] : 4.0;
            if ($hint < 1 || $hint > 60) {
                throw new \InvalidArgumentException("scenes[{$i}].duration_hint_secs must be between 1 and 60.");
            }

            $visual = is_array($scene['visual'] ?? null) ? $scene['visual'] : [];
            $type   = strtolower(trim((string) ($visual['type'] ?? '')));
            $ref    = trim((string) ($visual['url_or_prompt'] ?? ''));
            if (! in_array($type, self::VISUAL_TYPES, true)) {
                throw new \InvalidArgumentException("scenes[{$i}].visual.type must be one of: " . implode(', ', self::VISUAL_TYPES) . '.');
            }
            if ($type === 'screenshot' && ! self::screenshotHostAllowed($ref)) {
                throw new \InvalidArgumentException("scenes[{$i}].visual.url_or_prompt must be an https URL on aicountly.com (or a *.aicountly.com app page).");
            }
            if (in_array($type, ['image', 'stock'], true)) {
                if (preg_match('#^[a-z]+://#i', $ref)) {
                    throw new \InvalidArgumentException("scenes[{$i}].visual: {$type} visuals are generated as stills — send a prompt, not a URL.");
                }
                if (mb_strlen($ref) < 10 || mb_strlen($ref) > 1000) {
                    throw new \InvalidArgumentException("scenes[{$i}].visual.url_or_prompt must be a 10–1000 character image prompt.");
                }
            }
            if ($type === 'title_card' && $onScreen === '' && $ref === '') {
                throw new \InvalidArgumentException("scenes[{$i}]: a title_card needs on_screen_text.");
            }
            if ($type === 'title_card' && $onScreen === '') {
                $onScreen = mb_substr(self::clean($ref), 0, 90);
            }

            $out[] = [
                'order'              => $order,
                'voice_over_text'    => $voice,
                'on_screen_text'     => $onScreen,
                'visual'             => ['type' => $type, 'url_or_prompt' => $ref],
                'duration_hint_secs' => $hint,
            ];
        }
        if ($voiced === 0) {
            throw new \InvalidArgumentException('At least one scene needs voice_over_text.');
        }
        usort($out, static fn ($a, $b) => $a['order'] <=> $b['order']);
        return $out;
    }

    /** @return list<string> */
    private static function tags(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new \InvalidArgumentException('tags must be an array of strings.');
        }
        $tags  = [];
        $chars = 0;
        foreach ($raw as $tag) {
            $tag = trim(str_replace(['<', '>', ','], '', (string) $tag));
            if ($tag === '' || mb_strlen($tag) > 40 || in_array(mb_strtolower($tag), array_map('mb_strtolower', $tags), true)) {
                continue;
            }
            // YouTube counts a quoted multi-word tag with its quotes; cap at 450 of its 500.
            $cost = mb_strlen($tag) + (str_contains($tag, ' ') ? 2 : 0) + 1;
            if ($chars + $cost > 450 || count($tags) >= 20) {
                break;
            }
            $tags[]  = $tag;
            $chars  += $cost;
        }
        return $tags;
    }

    /**
     * @param list<array<string, mixed>> $scenes
     * @return list<array{title: string, scene_order: int}>
     */
    private static function chapters(mixed $raw, array $scenes, string $format): array
    {
        if (! is_array($raw) || $format !== 'vlog') {
            return [];
        }
        $orders = array_column($scenes, 'order');
        $out    = [];
        foreach ($raw as $i => $chapter) {
            if (! is_array($chapter)) {
                continue;
            }
            $title = mb_substr(self::clean((string) ($chapter['title'] ?? '')), 0, 60);
            $order = isset($chapter['scene_order']) && is_numeric($chapter['scene_order']) ? (int) $chapter['scene_order'] : null;
            if ($title === '' || $order === null) {
                throw new \InvalidArgumentException("chapters[{$i}] needs a title and the scene_order it starts at.");
            }
            if (! in_array($order, $orders, true)) {
                throw new \InvalidArgumentException("chapters[{$i}].scene_order {$order} is not a scene.");
            }
            $out[] = ['title' => $title, 'scene_order' => $order];
        }
        usort($out, static fn ($a, $b) => $a['scene_order'] <=> $b['scene_order']);
        return $out;
    }

    /** Trim, normalise whitespace, drop < and > (YouTube rejects them in titles/descriptions). */
    private static function clean(string $text, bool $multiline = false): string
    {
        $text = str_replace(['<', '>'], '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = $multiline ? preg_replace("/[ \t]+/u", ' ', $text) : preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }
}

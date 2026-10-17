<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Structured;

/**
 * Validates release_note / workflow_guide payloads with the same rules as the
 * aicountly.com receiver (aicountly-com includes/publisher/StructuredContent.php,
 * documented in docs/REACH_RECEIVER_CONTENT_TYPES.md §4–§5), so a payload the
 * site would refuse is refused here — before it costs a vetting call — and a
 * vetted revision that no longer fits is never sent.
 *
 * Every text field is plain text: markup and control characters are removed,
 * CRLF becomes LF, and over-long text is an error rather than cut short.
 * The result is the exact payload object sent to the receiver.
 */
final class StructuredPayloadValidator
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param array<string, mixed> $p
     * @return array{0: array<string, mixed>|null, 1: list<string>} [clean payload, errors]
     */
    public static function validate(string $contentType, array $p): array
    {
        $errors = [];
        $clean  = match ($contentType) {
            'release_note'   => self::releaseNote($p, $errors),
            'workflow_guide' => self::workflowGuide($p, $errors),
            default          => throw new \InvalidArgumentException("Unsupported structured content type '{$contentType}'."),
        };

        return $errors === [] ? [$clean, []] : [null, array_slice($errors, 0, 50)];
    }

    /** @param list<string> $errors */
    private static function releaseNote(array $p, array &$errors): array
    {
        $clean = [
            'slug'         => self::slug($p['slug'] ?? null, 'slug', $errors),
            'product_slug' => self::productSlug($p['product_slug'] ?? null, $errors),
            'version'      => self::text($p['version'] ?? null, 'version', 32, true, $errors),
            'title'        => self::text($p['title'] ?? null, 'title', 200, true, $errors),
            'date_iso'     => self::dateIso($p['date_iso'] ?? null, $errors),
            'intro'        => self::text($p['intro'] ?? null, 'intro', 2000, true, $errors),
            'sections'     => [],
        ];

        $sections = $p['sections'] ?? null;
        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            $errors[] = 'sections: required, a non-empty array.';
            return $clean;
        }
        if (count($sections) > 30) {
            $errors[] = 'sections: at most 30.';
            return $clean;
        }

        foreach ($sections as $i => $section) {
            $at = "sections[{$i}]";
            if (! is_array($section)) {
                $errors[] = "{$at}: must be an object.";
                continue;
            }
            $out        = ['heading' => self::text($section['heading'] ?? null, "{$at}.heading", 200, true, $errors)];
            $bullets    = self::textList($section['bullets'] ?? null, "{$at}.bullets", 50, 1000, $errors);
            $paragraphs = self::textList($section['paragraphs'] ?? null, "{$at}.paragraphs", 20, 4000, $errors);
            if ($bullets === [] && $paragraphs === []) {
                $errors[] = "{$at}: needs at least one entry in bullets or paragraphs.";
            }
            if ($paragraphs !== []) {
                $out['paragraphs'] = $paragraphs;
            }
            if ($bullets !== []) {
                $out['bullets'] = $bullets;
            }
            $clean['sections'][] = $out;
        }

        return $clean;
    }

    /** @param list<string> $errors */
    private static function workflowGuide(array $p, array &$errors): array
    {
        $clean = [
            'product_slug'  => self::productSlug($p['product_slug'] ?? null, $errors),
            'slug'          => self::slug($p['slug'] ?? null, 'slug', $errors),
            'title'         => self::text($p['title'] ?? null, 'title', 200, true, $errors),
            'blurb'         => self::text($p['blurb'] ?? null, 'blurb', 500, true, $errors),
            'audience'      => self::text($p['audience'] ?? null, 'audience', 300, true, $errors),
            'outcome'       => self::text($p['outcome'] ?? null, 'outcome', 500, true, $errors),
            'prerequisites' => self::textList($p['prerequisites'] ?? null, 'prerequisites', 20, 300, $errors),
            'steps'         => [],
            'faqs'          => [],
        ];

        $steps = $p['steps'] ?? null;
        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            $errors[] = 'steps: required, a non-empty array.';
        } elseif (count($steps) > 40) {
            $errors[] = 'steps: at most 40.';
        } else {
            foreach ($steps as $i => $step) {
                $at = "steps[{$i}]";
                if (! is_array($step)) {
                    $errors[] = "{$at}: must be an object.";
                    continue;
                }
                $out = [
                    'title' => self::text($step['title'] ?? null, "{$at}.title", 200, true, $errors),
                    'text'  => self::text($step['text'] ?? null, "{$at}.text", 4000, true, $errors),
                ];
                $tip = self::text($step['tip'] ?? null, "{$at}.tip", 1000, false, $errors);
                if ($tip !== '') {
                    $out['tip'] = $tip;
                }
                $image = self::httpsUrl($step['image_url'] ?? null, "{$at}.image_url", $errors);
                if ($image !== null) {
                    $out['image_url'] = $image;
                }
                $clean['steps'][] = $out;
            }
        }

        $faqs = $p['faqs'] ?? [];
        if ($faqs === null) {
            $faqs = [];
        }
        if (! is_array($faqs) || ! array_is_list($faqs)) {
            $errors[] = 'faqs: must be an array.';
        } elseif (count($faqs) > 20) {
            $errors[] = 'faqs: at most 20.';
        } else {
            foreach ($faqs as $i => $faq) {
                $at = "faqs[{$i}]";
                if (! is_array($faq)) {
                    $errors[] = "{$at}: must be an object.";
                    continue;
                }
                $clean['faqs'][] = [
                    'q' => self::text($faq['q'] ?? null, "{$at}.q", 300, true, $errors),
                    'a' => self::text($faq['a'] ?? null, "{$at}.a", 2000, true, $errors),
                ];
            }
        }

        $diagram = self::mermaid($p['diagram_mermaid'] ?? null, $errors);
        if ($diagram !== null) {
            $clean['diagram_mermaid'] = $diagram;
        }
        $image = self::httpsUrl($p['image_url'] ?? null, 'image_url', $errors);
        if ($image !== null) {
            $clean['image_url'] = $image;
        }

        return $clean;
    }

    /** @param list<string> $errors */
    public static function text(mixed $value, string $field, int $max, bool $required, array &$errors): string
    {
        if ($value === null) {
            $value = '';
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $errors[] = "{$field}: must be a string.";
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);
        $text = trim($text);

        if ($required && $text === '') {
            $errors[] = "{$field}: required.";
        } elseif (mb_strlen($text) > $max) {
            $errors[] = "{$field}: at most {$max} characters.";
        }

        return $text;
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private static function textList(mixed $value, string $field, int $maxItems, int $maxLength, array &$errors): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            $errors[] = "{$field}: must be an array of strings.";
            return [];
        }
        if (count($value) > $maxItems) {
            $errors[] = "{$field}: at most {$maxItems} items.";
            return [];
        }

        $out = [];
        foreach ($value as $i => $item) {
            $text = self::text($item, "{$field}[{$i}]", $maxLength, true, $errors);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /** @param list<string> $errors */
    public static function slug(mixed $value, string $field, array &$errors): string
    {
        $slug = is_string($value) ? strtolower(trim($value)) : '';
        if ($slug === '' || strlen($slug) > 120 || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $errors[] = "{$field}: required; lowercase letters, digits and single hyphens, at most 120 characters.";
        }

        return $slug;
    }

    /** @param list<string> $errors */
    public static function productSlug(mixed $value, array &$errors): string
    {
        $slug = is_string($value) ? strtolower(trim($value)) : '';
        if ($slug === '' || ! ProductCatalog::has($slug)) {
            $errors[] = 'product_slug: required; must be a product slug from the aicountly.com catalogue (GET v1/automation/release-plan lists them).';
        }

        return $slug;
    }

    /** @param list<string> $errors */
    private static function dateIso(mixed $value, array &$errors): string
    {
        $date = is_string($value) ? trim($value) : '';
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            $errors[] = 'date_iso: required, a calendar date as YYYY-MM-DD.';
        }

        return $date;
    }

    /** @param list<string> $errors */
    private static function httpsUrl(mixed $value, string $field, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $url   = is_string($value) ? trim($value) : '';
        $parts = $url !== '' ? parse_url($url) : false;
        if (
            $url === ''
            || strlen($url) > 1000
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || preg_match('/["\'<>\s]/', $url) === 1
        ) {
            $errors[] = "{$field}: must be an absolute https:// URL of at most 1000 characters.";
            return null;
        }

        return $url;
    }

    /** @param list<string> $errors */
    private static function mermaid(mixed $value, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            $errors[] = 'diagram_mermaid: must be a string.';
            return null;
        }

        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $value)) as $line) {
            if (preg_match('/^(%%\{|click\s|callback\s)/i', ltrim($line)) === 1) {
                continue;
            }
            $lines[] = rtrim((string) preg_replace('/<\/?[a-zA-Z][^>]*>/', ' ', $line));
        }
        $source = trim(implode("\n", $lines));
        if (preg_match('/javascript:|data:text\/html/i', $source) === 1) {
            $errors[] = 'diagram_mermaid: must not contain script URLs.';
            return null;
        }
        if (mb_strlen($source) > 8000) {
            $errors[] = 'diagram_mermaid: at most 8000 characters.';
            return null;
        }
        $first = strtok($source, "\n");
        if (
            $first === false
            || preg_match('/^\s*(flowchart|graph|sequenceDiagram|stateDiagram(-v2)?|journey|gantt|classDiagram|erDiagram|mindmap|timeline)\b/', $first) !== 1
        ) {
            $errors[] = 'diagram_mermaid: must start with a Mermaid diagram type (flowchart, graph, sequenceDiagram, stateDiagram, journey, gantt, classDiagram, erDiagram, mindmap, timeline).';
            return null;
        }

        return $source;
    }
}

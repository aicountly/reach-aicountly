<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

/**
 * Fills missing/blank required structured-output fields with safe defaults so
 * near-complete provider JSON (common with Gemini) can still pass schema
 * validation and become a content version.
 *
 * Never invents a fake article body from the title alone — empty body fields
 * stay empty so schema validation fails instead of saving "Untitled draft".
 */
final class StructuredOutputCoercer
{
    /** Placeholder bodies we must never treat as real draft content. */
    private const STUB_BODIES = [
        'untitled draft',
        'untitled',
        'n/a',
        'tbd',
        'todo',
        'placeholder',
        'lorem ipsum',
    ];

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public function coerce(array $data, array $schema): array
    {
        $data = $this->trimStringFields($data);
        $data = $this->deriveBodyFields($data);

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($schema['required'] ?? [] as $field) {
            if (! is_string($field)) {
                continue;
            }
            // Never invent article bodies — leave blank so validation fails.
            if (in_array($field, ['body_html', 'body_markdown', 'body_plain_text'], true)) {
                continue;
            }
            $prop = is_array($properties[$field] ?? null) ? $properties[$field] : [];
            if (! array_key_exists($field, $data) || $this->isBlankForSchema($data[$field], $prop)) {
                $data[$field] = $this->defaultForProperty($field, $prop, $data);
            }
        }

        foreach ($schema['required'] ?? [] as $field) {
            if (! is_string($field)) {
                continue;
            }
            if (in_array($field, ['body_html', 'body_markdown', 'body_plain_text'], true)) {
                continue;
            }
            $prop = is_array($properties[$field] ?? null) ? $properties[$field] : [];
            if ($this->isBlankForSchema($data[$field] ?? null, $prop)) {
                $data[$field] = $this->defaultForProperty($field, $prop, $data);
            }
        }

        if (($schema['additionalProperties'] ?? true) === false && $properties !== []) {
            $allowed = array_flip(array_keys($properties));
            $data    = array_intersect_key($data, $allowed);
        }

        // Gemini often overshoots summary/meta maxLength; truncate rather than fail.
        $data = $this->truncateToSchemaLimits($data, $properties);

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,array<string,mixed>> $properties
     * @return array<string,mixed>
     */
    private function truncateToSchemaLimits(array $data, array $properties): array
    {
        foreach ($properties as $field => $prop) {
            if (! is_array($prop) || ! array_key_exists($field, $data) || ! is_string($data[$field])) {
                continue;
            }
            $max = (int) ($prop['maxLength'] ?? 0);
            if ($max > 0 && mb_strlen($data[$field]) > $max) {
                $data[$field] = mb_substr($data[$field], 0, $max);
            }
        }

        return $data;
    }

    /**
     * True when body text is missing or is a known stub / title-only fake.
     */
    public static function isStubBody(?string $html, ?string $markdown = null, ?string $plain = null, ?string $title = null): bool
    {
        $text = trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            $text = trim((string) $plain);
        }
        if ($text === '') {
            $text = trim(strip_tags(str_replace(['**', '*', '`', '#'], '', (string) $markdown)));
        }
        if ($text === '') {
            return true;
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (in_array($normalized, self::STUB_BODIES, true)) {
            return true;
        }
        foreach (self::STUB_BODIES as $stub) {
            if ($normalized === $stub || str_starts_with($normalized, $stub . ' ')) {
                return true;
            }
        }

        $titleNorm = strtolower(trim((string) $title));
        if ($titleNorm !== '' && $normalized === $titleNorm) {
            return true;
        }

        // Extremely short strings are not articles (keep this bar low — schema
        // minLength enforces production word count separately).
        if (mb_strlen($text) < 40 || str_word_count($text) < 8) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function trimStringFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeAlternateBodyKeys(array $data): array
    {
        $aliases = [
            'body_html'       => ['html', 'content_html', 'article_html', 'content'],
            'body_markdown'   => ['markdown', 'content_markdown', 'article_markdown'],
            'body_plain_text' => ['plain_text', 'text', 'article', 'body', 'content_text'],
        ];
        foreach ($aliases as $canonical => $keys) {
            if (trim((string) ($data[$canonical] ?? '')) !== '') {
                continue;
            }
            foreach ($keys as $key) {
                $candidate = $data[$key] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $data[$canonical] = trim($candidate);
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Build body fields from sections[] when the model filled outline content
     * but left body_html/markdown/plain empty (common Gemini partial outputs).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function synthesizeBodyFromSections(array $data): array
    {
        $html  = trim((string) ($data['body_html'] ?? ''));
        $md    = trim((string) ($data['body_markdown'] ?? ''));
        $plain = trim((string) ($data['body_plain_text'] ?? ''));
        if ($html !== '' || $md !== '' || $plain !== '') {
            return $data;
        }

        $sections = $data['sections'] ?? null;
        if (! is_array($sections) || $sections === []) {
            return $data;
        }

        $htmlParts  = [];
        $mdParts    = [];
        $plainParts = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $heading = trim((string) ($section['heading'] ?? $section['title'] ?? ''));
            $body    = trim((string) ($section['body'] ?? $section['content'] ?? $section['text'] ?? ''));
            if ($heading === '' && $body === '') {
                continue;
            }
            if ($heading !== '') {
                $safeH = htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $htmlParts[]  = '<h2>' . $safeH . '</h2>';
                $mdParts[]    = '## ' . $heading;
                $plainParts[] = $heading;
            }
            if ($body !== '') {
                $safeB = htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $htmlParts[]  = '<p>' . nl2br($safeB) . '</p>';
                $mdParts[]    = $body;
                $plainParts[] = $body;
            }
        }

        if ($htmlParts === []) {
            return $data;
        }

        $data['body_html']       = implode("\n", $htmlParts);
        $data['body_markdown']   = implode("\n\n", $mdParts);
        $data['body_plain_text'] = implode("\n\n", $plainParts);

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function deriveBodyFields(array $data): array
    {
        $data  = $this->normalizeAlternateBodyKeys($data);
        $data  = $this->synthesizeBodyFromSections($data);
        $html  = trim((string) ($data['body_html'] ?? ''));
        $md    = trim((string) ($data['body_markdown'] ?? ''));
        $plain = trim((string) ($data['body_plain_text'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));

        if ($plain === '' && $md !== '') {
            $plain = trim(strip_tags(str_replace(['**', '*', '`'], '', $md)));
        }
        if ($plain === '' && $html !== '') {
            $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($html === '' && $md !== '') {
            $escaped = htmlspecialchars($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html    = '<p>' . nl2br($escaped) . '</p>';
        }
        if ($html === '' && $plain !== '') {
            $escaped = htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html    = '<p>' . nl2br($escaped) . '</p>';
        }
        if ($md === '' && $plain !== '') {
            $md = $plain;
        }
        if ($md === '' && $html !== '') {
            $md = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Clear stub bodies so they do not pass minLength via title echo.
        if (self::isStubBody($html, $md, $plain, $title)) {
            $html  = '';
            $md    = '';
            $plain = '';
            unset($data['body_html'], $data['body_markdown'], $data['body_plain_text']);
        } else {
            // Always materialise all three body representations so optional
            // minLength properties are not left as empty strings from the model.
            $data['body_html']       = $html;
            $data['body_markdown']   = $md !== '' ? $md : $plain;
            $data['body_plain_text'] = $plain !== '' ? $plain : trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if ($title === '' && $plain !== '') {
            $title = mb_substr($plain, 0, 80);
            $data['title'] = $title;
        }
        if ($title === '') {
            $title = 'Untitled draft';
            $data['title'] = $title;
        }

        if (trim((string) ($data['slug_suggestion'] ?? '')) === '') {
            $slug = strtolower($title);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
            $data['slug_suggestion'] = trim($slug, '-') ?: 'untitled-draft';
        }

        if (trim((string) ($data['summary'] ?? '')) === '' || self::isStubBody(null, null, (string) ($data['summary'] ?? ''), $title)) {
            $data['summary'] = $plain !== '' ? mb_substr($plain, 0, 280) : '';
        }
        if (trim((string) ($data['meta_title'] ?? '')) === '') {
            $data['meta_title'] = mb_substr($title, 0, 70);
        }
        if (trim((string) ($data['meta_description'] ?? '')) === '') {
            $source = trim((string) ($data['summary'] ?? '')) ?: $plain;
            $data['meta_description'] = $source !== '' ? mb_substr($source, 0, 160) : '';
        }
        if (trim((string) ($data['primary_cta'] ?? '')) === '') {
            $data['primary_cta'] = 'Learn More';
        }

        if (! isset($data['reading_time_minutes']) || (int) $data['reading_time_minutes'] < 1) {
            $words = str_word_count($plain !== '' ? $plain : strip_tags($html));
            $data['reading_time_minutes'] = max(1, (int) ceil(max(1, $words) / 200));
        }

        if (! isset($data['claims_used']) || ! is_array($data['claims_used'])) {
            $data['claims_used'] = [];
        }
        if (! isset($data['citations_used']) || ! is_array($data['citations_used'])) {
            $data['citations_used'] = [];
        }
        if (! isset($data['risk_notes']) || ! is_array($data['risk_notes'])) {
            $data['risk_notes'] = [];
        }
        if (! isset($data['sections']) || ! is_array($data['sections'])) {
            $data['sections'] = [];
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $prop
     */
    private function isBlankForSchema(mixed $value, array $prop): bool
    {
        if ($value === null) {
            return true;
        }

        $types = (array) ($prop['type'] ?? 'string');
        if (is_string($value) && trim($value) === '') {
            $minLength = (int) ($prop['minLength'] ?? 0);
            return $minLength > 0 || ! in_array('null', $types, true);
        }

        if (is_array($value) && $value === [] && ($prop['type'] ?? '') === 'object') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $prop
     * @param array<string,mixed> $data
     */
    private function defaultForProperty(string $field, array $prop, array $data): mixed
    {
        $type = $prop['type'] ?? 'string';
        if (is_array($type)) {
            $type = $type[0] ?? 'string';
        }

        $title   = trim((string) ($data['title'] ?? 'Untitled draft')) ?: 'Untitled draft';
        $plain   = trim((string) ($data['body_plain_text'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));

        return match ($type) {
            'array'   => [],
            'integer', 'number' => (int) ($prop['minimum'] ?? 1),
            'boolean' => false,
            'object'  => [],
            default   => match ($field) {
                'primary_cta'      => 'Learn More',
                'title'            => $title,
                'summary'          => $summary !== '' ? $summary : ($plain !== '' ? mb_substr($plain, 0, 280) : ''),
                'meta_title'       => mb_substr($title, 0, 70),
                'meta_description' => $summary !== '' ? mb_substr($summary, 0, 160) : ($plain !== '' ? mb_substr($plain, 0, 160) : ''),
                'slug_suggestion'  => 'untitled-draft',
                // Never invent body content from the title.
                'body_html', 'body_markdown', 'body_plain_text' => '',
                default            => $title,
            },
        };
    }
}

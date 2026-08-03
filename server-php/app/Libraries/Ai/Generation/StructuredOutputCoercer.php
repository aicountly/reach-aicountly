<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

/**
 * Fills missing/blank required structured-output fields with safe defaults so
 * near-complete provider JSON (common with Gemini) can still pass schema
 * validation and become a content version.
 */
final class StructuredOutputCoercer
{
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
            $prop = is_array($properties[$field] ?? null) ? $properties[$field] : [];
            if (! array_key_exists($field, $data) || $this->isBlankForSchema($data[$field], $prop)) {
                $data[$field] = $this->defaultForProperty($field, $prop, $data);
            }
        }

        // Final pass: never leave required minLength strings empty after defaults.
        foreach ($schema['required'] ?? [] as $field) {
            if (! is_string($field)) {
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

        return $data;
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
    private function deriveBodyFields(array $data): array
    {
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

        if ($html !== '') {
            $data['body_html'] = $html;
        }
        if ($md !== '') {
            $data['body_markdown'] = $md;
        }
        if ($plain !== '') {
            $data['body_plain_text'] = $plain;
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

        if (trim((string) ($data['summary'] ?? '')) === '') {
            $data['summary'] = mb_substr($plain !== '' ? $plain : $title, 0, 280);
        }
        if (trim((string) ($data['meta_title'] ?? '')) === '') {
            $data['meta_title'] = mb_substr($title, 0, 70);
        }
        if (trim((string) ($data['meta_description'] ?? '')) === '') {
            $source = trim((string) ($data['summary'] ?? '')) ?: ($plain !== '' ? $plain : $title);
            $data['meta_description'] = mb_substr($source, 0, 160);
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
            // Empty string fails minLength and is useless for required content fields.
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

        $title = trim((string) ($data['title'] ?? 'Untitled draft')) ?: 'Untitled draft';
        $plain = trim((string) ($data['body_plain_text'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));

        return match ($type) {
            'array'   => [],
            'integer', 'number' => (int) ($prop['minimum'] ?? 1),
            'boolean' => false,
            'object'  => [],
            default   => match ($field) {
                'primary_cta'      => 'Learn More',
                'title'            => $title,
                'summary'          => $summary !== '' ? $summary : ($plain !== '' ? mb_substr($plain, 0, 280) : $title),
                'meta_title'       => mb_substr($title, 0, 70),
                'meta_description' => mb_substr($summary !== '' ? $summary : ($plain !== '' ? $plain : $title), 0, 160),
                'slug_suggestion'  => 'untitled-draft',
                'body_html'        => $plain !== '' ? '<p>' . htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>' : '<p>' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>',
                'body_markdown'    => $plain !== '' ? $plain : $title,
                'body_plain_text'  => $plain !== '' ? $plain : $title,
                default            => $title,
            },
        };
    }
}

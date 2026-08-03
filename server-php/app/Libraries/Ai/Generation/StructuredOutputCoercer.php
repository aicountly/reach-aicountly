<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

/**
 * Fills missing required structured-output fields with safe defaults so
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
        $data = $this->deriveBodyFields($data);

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($schema['required'] ?? [] as $field) {
            if (! is_string($field) || array_key_exists($field, $data)) {
                continue;
            }
            $prop = is_array($properties[$field] ?? null) ? $properties[$field] : [];
            $data[$field] = $this->defaultForProperty($field, $prop, $data);
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
    private function deriveBodyFields(array $data): array
    {
        $html  = trim((string) ($data['body_html'] ?? ''));
        $md    = trim((string) ($data['body_markdown'] ?? ''));
        $plain = trim((string) ($data['body_plain_text'] ?? ''));

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

        if (empty($data['slug_suggestion']) && ! empty($data['title'])) {
            $slug = strtolower(trim((string) $data['title']));
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
            $data['slug_suggestion'] = trim($slug, '-');
        }

        if (empty($data['summary']) && $plain !== '') {
            $data['summary'] = mb_substr($plain, 0, 280);
        }
        if (empty($data['summary']) && ! empty($data['title'])) {
            $data['summary'] = (string) $data['title'];
        }
        if (empty($data['meta_title']) && ! empty($data['title'])) {
            $data['meta_title'] = mb_substr((string) $data['title'], 0, 70);
        }
        if (empty($data['meta_description'])) {
            $source = (string) ($data['summary'] ?? $plain ?: ($data['title'] ?? 'Article summary'));
            $data['meta_description'] = mb_substr($source, 0, 160);
        }

        if (! isset($data['reading_time_minutes']) || (int) $data['reading_time_minutes'] < 1) {
            $words = str_word_count($plain !== '' ? $plain : strip_tags($html));
            $data['reading_time_minutes'] = max(1, (int) ceil(max(1, $words) / 200));
        }

        return $data;
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

        return match ($type) {
            'array'   => [],
            'integer', 'number' => (int) ($prop['minimum'] ?? 1),
            'boolean' => false,
            'object'  => [],
            default   => match ($field) {
                'primary_cta' => 'Learn More',
                'title'       => (string) ($data['meta_title'] ?? 'Untitled'),
                'summary'     => '',
                default       => '',
            },
        };
    }
}

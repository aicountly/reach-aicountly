<?php

namespace App\Models\Cast;

use CodeIgniter\DataCaster\Cast\BaseCast;

/**
 * `pg-text-array` — a PostgreSQL text[] column as a PHP list.
 *
 * reach_community_spaces.allowed_content_types and reach_community_official_identities.authorised_scopes
 * are text[] but were cast `json-array`: a create with values sent '["faq"]', which PostgreSQL
 * refuses ("malformed array literal"), and reading a non-empty array ({faq,how_to}) was not JSON.
 * They only worked while every row held '{}' (which happens to decode as JSON).
 */
final class PgTextArrayCast extends BaseCast
{
    /** @return list<string>|null */
    public static function get(mixed $value, array $params = [], ?object $helper = null): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $inner = trim((string) $value);
        if ($inner === '' || $inner === '{}') {
            return [];
        }
        if (str_starts_with($inner, '[')) { // a value written by the old json cast
            $decoded = json_decode($inner, true);

            return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }
        $inner = substr($inner, 1, -1);
        $out = [];
        foreach (str_getcsv($inner, ',', '"', '\\') as $item) {
            $out[] = (string) preg_replace('/\\\\(.)/s', '$1', (string) $item); // PostgreSQL escapes \" and \\
        }

        return $out;
    }

    public static function set(mixed $value, array $params = [], ?object $helper = null): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }
        $items = array_map(static fn ($v): string => '"' . addcslashes((string) $v, '"\\') . '"', array_values((array) $value));

        return '{' . implode(',', $items) . '}';
    }
}

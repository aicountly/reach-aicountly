<?php

namespace App\Libraries\Support;

/**
 * Normalise PostgreSQL / PDO boolean wire values for PHP truthiness checks.
 *
 * CodeIgniter's Postgre driver often returns booleans as the strings "t" / "f".
 * Using empty()/!empty() on those is wrong: !empty('f') === true.
 */
final class PgBoolean
{
    public static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 't', 'yes', 'on'], true);
    }

    public static function isFalse(mixed $value): bool
    {
        return ! self::isTrue($value);
    }
}

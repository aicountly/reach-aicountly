<?php

declare(strict_types=1);

namespace App\Libraries\Support;

use App\Libraries\Settings\ReachConfig;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * How Reach shows a time to a person: dd-mm-yyyy HH:mm in Indian Standard
 * Time with an "IST" label (the `ui.timezone` setting, default Asia/Kolkata).
 * The web helper web/src/utils/formatDate.js renders the same way.
 *
 * Use it for human text only — emails, notifications, CLI tables, CSV
 * exports. Storage, JSON and ISO fields stay UTC (App::$appTimezone; the
 * database session zone must be UTC too — docs/operations/TIMEZONES.md).
 *
 * Reading values: a DateTimeInterface keeps its zone, an int is a Unix
 * timestamp, a string with an offset ("2026-10-18 01:04:00+00", "…Z") is
 * absolute, and a zone-less "Y-m-d H:i:s" is UTC — that is what date() and a
 * TIMESTAMP column mean on this server. A date-only "Y-m-d" is a calendar
 * date and is never moved to another day. docs/operations/TIMEZONES.md
 */
final class DisplayTime
{
    public const DEFAULT_ZONE = 'Asia/Kolkata';

    /** Short labels where PHP's abbreviation is unhelpful ("+0530"). */
    private const LABELS = ['Asia/Kolkata' => 'IST', 'Asia/Calcutta' => 'IST', 'UTC' => 'UTC', 'Etc/UTC' => 'UTC'];

    private static ?string $zoneOverride = null;

    /** The display zone name: `ui.timezone` when valid, else Asia/Kolkata. */
    public static function zoneName(): string
    {
        if (self::$zoneOverride !== null) {
            return self::$zoneOverride;
        }
        try {
            $configured = trim((string) ReachConfig::get('ui.timezone', self::DEFAULT_ZONE));
        } catch (\Throwable) {
            $configured = '';
        }
        return self::valid($configured) ? $configured : self::DEFAULT_ZONE;
    }

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::zoneName());
    }

    /** "IST" for Asia/Kolkata, "UTC" for UTC, else PHP's abbreviation. */
    public static function label(?string $zone = null): string
    {
        $zone ??= self::zoneName();
        if (isset(self::LABELS[$zone])) {
            return self::LABELS[$zone];
        }
        return self::valid($zone) ? (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('T') : $zone;
    }

    /** Current time in the display zone. */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    /** The value as an instant, or null when empty / unreadable. */
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) >= 9)) {
            return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('UTC'));
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        // Postgres' hour-only offset "+00" / "+05" — PHP reads it, but be explicit.
        $raw = (string) preg_replace('/(\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)\s*([+-]\d{2})$/', '$1$2:00', $raw);
        try {
            // The UTC zone applies only when the string carries none.
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * "18-10-2026 06:34 IST" (24-hour, display zone); date-only values render
     * as their date. $zone overrides the setting (a caller that must stay
     * free of I/O, or that already knows its zone).
     */
    public static function format(mixed $value, string $fallback = '—', bool $withLabel = true, ?string $zone = null): string
    {
        if (is_string($value) && self::isDateOnly($value)) {
            return self::date($value, $fallback);
        }
        $at = self::parse($value);
        if ($at === null) {
            return $fallback;
        }
        $zone = $zone !== null && self::valid($zone) ? $zone : self::zoneName();
        $text = $at->setTimezone(new DateTimeZone($zone))->format('d-m-Y H:i');
        return $withLabel ? $text . ' ' . self::label($zone) : $text;
    }

    /** "06:34 IST". */
    public static function time(mixed $value, string $fallback = '—', bool $withLabel = true): string
    {
        $at = is_string($value) && self::isDateOnly($value) ? null : self::parse($value);
        if ($at === null) {
            return $fallback;
        }
        $text = $at->setTimezone(self::zone())->format('H:i');
        return $withLabel ? $text . ' ' . self::label() : $text;
    }

    /** "18-10-2026": the display-zone date of an instant, or a date-only value as is. */
    public static function date(mixed $value, string $fallback = '—'): string
    {
        if (is_string($value) && self::isDateOnly($value)) {
            [$y, $m, $d] = explode('-', trim($value));
            return checkdate((int) $m, (int) $d, (int) $y) ? "{$d}-{$m}-{$y}" : $fallback;
        }
        $at = self::parse($value);
        return $at === null ? $fallback : $at->setTimezone(self::zone())->format('d-m-Y');
    }

    /**
     * A UTC wall-clock hour as "HH:MM UTC (HH:MM IST)" — for schedules that
     * are defined in UTC (reach:schedule's timetable, routine crons).
     */
    public static function utcSlot(int $hour, int $minute = 0): string
    {
        $utc = (new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')))->setTime($hour, $minute);
        return sprintf('%s UTC (%s %s)', $utc->format('H:i'), $utc->setTimezone(self::zone())->format('H:i'), self::label());
    }

    /** Tests: force a zone (null = back to the setting). */
    public static function useZone(?string $zone): void
    {
        self::$zoneOverride = $zone !== null && self::valid($zone) ? $zone : null;
    }

    private static function isDateOnly(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value));
    }

    private static function valid(string $zone): bool
    {
        if ($zone === '') {
            return false;
        }
        try {
            new DateTimeZone($zone);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

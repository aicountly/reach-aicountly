<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

/**
 * Calendar arithmetic for the bot plan, always on IST (Asia/Kolkata) dates.
 * Weeks run Monday–Sunday; months are calendar months. Pure.
 */
final class PlanPeriods
{
    public const TZ = 'Asia/Kolkata';

    /** Plan periods the page and API offer, in display order. */
    public const PLAN_KEYS = ['today', 'tomorrow', 'this_week', 'next_week', 'this_month', 'next_month'];

    /** Past ranges for performance. Every range ends yesterday at the latest. */
    public const RANGE_KEYS = ['yesterday', 'last_7_days', 'last_30_days', 'this_week', 'last_week', 'this_month', 'last_month'];

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(self::TZ);
    }

    /** Today's IST date. */
    public static function today(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now'))->setTimezone(self::tz())->format('Y-m-d');
    }

    /**
     * @return array{key:string, type:string, start:string, end:string, days:int, label:string}
     */
    public static function plan(string $key, ?\DateTimeImmutable $now = null): array
    {
        $today = new \DateTimeImmutable(self::today($now), self::tz());

        [$type, $start, $end, $label] = match ($key) {
            'today'      => ['day', $today, $today, 'Today'],
            'tomorrow'   => ['day', $today->modify('+1 day'), $today->modify('+1 day'), 'Tomorrow'],
            'this_week'  => ['week', self::weekStart($today), self::weekStart($today)->modify('+6 days'), 'This week'],
            'next_week'  => ['week', self::weekStart($today)->modify('+7 days'), self::weekStart($today)->modify('+13 days'), 'Next week'],
            'this_month' => ['month', $today->modify('first day of this month'), $today->modify('last day of this month'), 'This month'],
            'next_month' => ['month', $today->modify('first day of next month'), $today->modify('last day of next month'), 'Next month'],
            default      => throw new \InvalidArgumentException('period must be one of: ' . implode(', ', self::PLAN_KEYS)),
        };

        return [
            'key'   => $key,
            'type'  => $type,
            'start' => $start->format('Y-m-d'),
            'end'   => $end->format('Y-m-d'),
            'days'  => self::days($start->format('Y-m-d'), $end->format('Y-m-d')),
            'label' => $label,
        ];
    }

    /**
     * A past range, clipped so it never includes today.
     *
     * @return array{key:string, from:string, to:string, days:int, label:string}
     */
    public static function range(string $key, ?\DateTimeImmutable $now = null): array
    {
        $today     = new \DateTimeImmutable(self::today($now), self::tz());
        $yesterday = $today->modify('-1 day');

        [$from, $to, $label] = match ($key) {
            'yesterday'    => [$yesterday, $yesterday, 'Yesterday'],
            'last_7_days'  => [$today->modify('-7 days'), $yesterday, 'Last 7 days'],
            'last_30_days' => [$today->modify('-30 days'), $yesterday, 'Last 30 days'],
            'this_week'    => [self::weekStart($today), $yesterday, 'This week so far'],
            'last_week'    => [self::weekStart($today)->modify('-7 days'), self::weekStart($today)->modify('-1 day'), 'Last week'],
            'this_month'   => [$today->modify('first day of this month'), $yesterday, 'This month so far'],
            'last_month'   => [$today->modify('first day of last month'), $today->modify('last day of last month'), 'Last month'],
            default        => throw new \InvalidArgumentException('range must be one of: ' . implode(', ', self::RANGE_KEYS)),
        };
        // On a Monday / the 1st, "so far" is empty: fall back to yesterday alone.
        if ($from > $to) {
            $from = $to;
        }

        return [
            'key'   => $key,
            'from'  => $from->format('Y-m-d'),
            'to'    => $to->format('Y-m-d'),
            'days'  => self::days($from->format('Y-m-d'), $to->format('Y-m-d')),
            'label' => $label,
        ];
    }

    /** @return list<string> every date from $from to $to inclusive */
    public static function dates(string $from, string $to): array
    {
        $out = [];
        $d   = new \DateTimeImmutable($from, self::tz());
        $end = new \DateTimeImmutable($to, self::tz());
        while ($d <= $end) {
            $out[] = $d->format('Y-m-d');
            $d     = $d->modify('+1 day');
        }

        return $out;
    }

    public static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date, self::tz()))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** ISO weekday, 1 = Monday … 7 = Sunday. */
    public static function weekday(string $date): int
    {
        return (int) (new \DateTimeImmutable($date, self::tz()))->format('N');
    }

    /** The UTC instant of IST midnight at the start of $date (for timestamptz comparisons). */
    public static function istMidnightUtc(string $date): string
    {
        return (new \DateTimeImmutable($date . ' 00:00:00', self::tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }

    private static function weekStart(\DateTimeImmutable $d): \DateTimeImmutable
    {
        $n = (int) $d->format('N');

        return $n === 1 ? $d : $d->modify('-' . ($n - 1) . ' days');
    }
}

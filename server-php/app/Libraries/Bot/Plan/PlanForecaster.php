<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

/**
 * Outcome / demand forecasts from a metric's own history. Deterministic and
 * explainable, no model call:
 *
 *   window      the last 28 days that have data (days before the source's
 *               first record, and days a source has not reported, are skipped)
 *   level       mean of the window
 *   trend       least-squares slope per day over the window
 *   projection  level + slope × (days from the window's centre to the target
 *               period's centre), clamped to 50 %–150 % of the level, ≥ 0
 *   target      projection × days in the period
 *   range       target ± 1 standard deviation × √days
 *   confidence  high: 28 days and coefficient of variation < 0.5
 *               medium: ≥ 21 days and CV < 1
 *               low: otherwise
 *
 * Fewer than MIN_DAYS days → no number: "baseline being established".
 */
final class PlanForecaster
{
    public const WINDOW   = 28;
    public const MIN_DAYS = 14;

    /**
     * @param array<string, float|null> $series   date => value (null = no data that day)
     * @param string                    $historyEnd last date the history may use (yesterday)
     * @param string                    $periodStart
     * @param string                    $periodEnd
     * @return array{status:string, target:?float, low:?float, high:?float, method:string, confidence:string, days_used:int, level:?float, slope:?float}
     */
    public static function forecast(array $series, ?string $firstDate, string $historyEnd, string $periodStart, string $periodEnd, string $unit = 'count'): array
    {
        ksort($series);
        $points = [];
        foreach ($series as $date => $value) {
            if ($value === null || $date > $historyEnd || ($firstDate !== null && $date < $firstDate)) {
                continue;
            }
            $points[$date] = (float) $value;
        }
        if ($firstDate === null) {
            $points = [];
        }
        $points = array_slice($points, -self::WINDOW, null, true);
        $n      = count($points);

        if ($n < self::MIN_DAYS) {
            return [
                'status' => 'baseline', 'target' => null, 'low' => null, 'high' => null, 'confidence' => 'none',
                'days_used' => $n, 'level' => null, 'slope' => null,
                'method' => sprintf('Baseline being established: %d of %d days of history needed for a forecast.', $n, self::MIN_DAYS),
            ];
        }

        $values = array_values($points);
        $dates  = array_keys($points);
        $level  = array_sum($values) / $n;
        // x = day offset from the first point, so gaps in the series are respected.
        $x0 = new \DateTimeImmutable($dates[0]);
        $xs = array_map(static fn (string $d): float => (float) $x0->diff(new \DateTimeImmutable($d))->days, $dates);
        $mx = array_sum($xs) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach ($xs as $i => $x) {
            $num += ($x - $mx) * ($values[$i] - $level);
            $den += ($x - $mx) ** 2;
        }
        $slope = $den > 0 ? $num / $den : 0.0;
        $var   = 0.0;
        foreach ($values as $v) {
            $var += ($v - $level) ** 2;
        }
        $sd = sqrt($var / max(1, $n - 1));
        $cv = $level > 0 ? $sd / $level : ($sd > 0 ? INF : 0.0);

        $days   = PlanPeriods::days($periodStart, $periodEnd);
        $centre = $x0->diff(new \DateTimeImmutable($periodStart))->days + ($days - 1) / 2;
        $proj   = $level + $slope * ($centre - $mx);
        $proj   = max(0.0, min($level * 1.5, max($level * 0.5, $proj)));

        $target = self::round($proj * $days, $unit);
        $spread = $sd * sqrt($days);
        $conf   = ($n >= self::WINDOW && $cv < 0.5) ? 'high' : (($n >= 21 && $cv < 1.0) ? 'medium' : 'low');

        return [
            'status'     => 'target',
            'target'     => $target,
            'low'        => self::round(max(0.0, $proj * $days - $spread), $unit),
            'high'       => self::round($proj * $days + $spread, $unit),
            'confidence' => $conf,
            'days_used'  => $n,
            'level'      => round($level, 2),
            'slope'      => round($slope, 3),
            'method'     => sprintf(
                '%d-day trailing mean %s/day, trend %s%s/day (least squares, clamped to ±50%%), × %d day%s. Range ±1 s.d.',
                $n, self::fmt($level), $slope >= 0 ? '+' : '', self::fmt($slope), $days, $days === 1 ? '' : 's'
            ),
        ];
    }

    private static function round(float $v, string $unit): float
    {
        return $unit === 'inr' ? round($v, 2) : round($v, $v < 10 ? 1 : 0);
    }

    private static function fmt(float $v): string
    {
        return abs($v) >= 100 ? number_format($v, 0, '.', '') : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard;

/**
 * Small, explainable forecasts for the command centre.
 *
 * Method (stated with every result): an ordinary least-squares line through
 * the daily values, plus a day-of-week adjustment (the mean residual of each
 * weekday) once there are at least two full weeks, and an 80 % prediction
 * band from the spread of what is left (±1.28 σ, widened for distance from
 * the fitted data). Values never go below zero.
 *
 * Honest by construction: fewer than `min_points` days → no forecast, with
 * the reason; a series averaging under `low_volume` per day is flagged so the
 * page can say the band is wide because the numbers are small.
 */
final class Forecast
{
    public const MIN_POINTS = 28;
    private const Z80       = 1.2816;

    /**
     * @param array<string, int|float> $byDate  'Y-m-d' => value; missing days inside the range count as 0
     * @param array{min_points?: int, low_volume?: float, today?: string} $opts
     * @return array<string, mixed>
     */
    public static function daily(array $byDate, int $horizon = 28, array $opts = []): array
    {
        $min   = max(7, (int) ($opts['min_points'] ?? self::MIN_POINTS));
        $low   = (float) ($opts['low_volume'] ?? 5.0);
        $dates = array_keys($byDate);
        sort($dates);

        if ($dates === []) {
            return ['available' => false, 'points' => 0, 'reason' => "No data yet — a forecast needs at least {$min} days."];
        }

        $start  = new \DateTimeImmutable($dates[0]);
        $end    = new \DateTimeImmutable(end($dates));
        $values = [];
        $wd     = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $values[] = (float) ($byDate[$d->format('Y-m-d')] ?? 0);
            $wd[]     = (int) $d->format('N');
        }
        $n = count($values);
        if ($n < $min) {
            return ['available' => false, 'points' => $n, 'reason' => "Not enough data yet: {$n} of {$min} days needed for a forecast."];
        }

        // Least squares y = a + b·t
        $tMean = ($n - 1) / 2;
        $yMean = array_sum($values) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($values as $t => $y) {
            $sxx += ($t - $tMean) ** 2;
            $sxy += ($t - $tMean) * ($y - $yMean);
        }
        $b = $sxx > 0 ? $sxy / $sxx : 0.0;
        $a = $yMean - $b * $tMean;

        // Day-of-week effect from the residuals (only with two full weeks).
        $seasonal = $n >= 14;
        $effect   = array_fill(1, 7, 0.0);
        if ($seasonal) {
            $sum = array_fill(1, 7, 0.0);
            $cnt = array_fill(1, 7, 0);
            foreach ($values as $t => $y) {
                $sum[$wd[$t]] += $y - ($a + $b * $t);
                $cnt[$wd[$t]]++;
            }
            foreach ($effect as $w => $_) {
                $effect[$w] = $cnt[$w] > 0 ? $sum[$w] / $cnt[$w] : 0.0;
            }
        }

        $sse = 0.0;
        foreach ($values as $t => $y) {
            $sse += ($y - ($a + $b * $t + $effect[$wd[$t]])) ** 2;
        }
        $dof   = max(1, $n - ($seasonal ? 9 : 2));
        $sigma = sqrt($sse / $dof);

        $series = [];
        $total  = 0.0;
        $lastWd = $wd[$n - 1];
        for ($h = 1; $h <= $horizon; $h++) {
            $t     = $n - 1 + $h;
            $w     = (($lastWd - 1 + $h) % 7) + 1;
            $yhat  = $a + $b * $t + $effect[$w];
            $width = self::Z80 * $sigma * sqrt(1 + 1 / $n + (($t - $tMean) ** 2) / max($sxx, 1e-9));
            $series[] = [
                'date'  => $end->modify("+{$h} day")->format('Y-m-d'),
                'value' => round(max(0.0, $yhat), 2),
                'low'   => round(max(0.0, $yhat - $width), 2),
                'high'  => round(max(0.0, $yhat + $width), 2),
            ];
            $total += max(0.0, $yhat);
        }
        // Total over the horizon: errors treated as independent day to day.
        $totalWidth = self::Z80 * $sigma * sqrt($horizon);

        $recent = array_slice($values, -min($horizon, $n));

        return [
            'available'      => true,
            'method'         => 'Least-squares linear trend over the last ' . $n . ' days'
                . ($seasonal ? ' with a day-of-week adjustment' : '')
                . '; 80% band from the residual spread.',
            'points'         => $n,
            'from'           => $start->format('Y-m-d'),
            'to'             => $end->format('Y-m-d'),
            'slope_per_day'  => round($b, 4),
            // Flat: the trend moves the daily value by under 10 % of its mean
            // across the horizon.
            'trend'          => abs($b) * $horizon < max(0.5, 0.1 * $yMean) ? 'flat' : ($b > 0 ? 'up' : 'down'),
            'mean_per_day'   => round($yMean, 2),
            'low_volume'     => $yMean < $low,
            'horizon_days'   => $horizon,
            'actual_last'    => ['days' => count($recent), 'total' => round(array_sum($recent), 2)],
            'projected'      => [
                'total' => round($total, 1),
                'low'   => round(max(0.0, $total - $totalWidth), 1),
                'high'  => round($total + $totalWidth, 1),
            ],
            'series'         => $series,
        ];
    }
}

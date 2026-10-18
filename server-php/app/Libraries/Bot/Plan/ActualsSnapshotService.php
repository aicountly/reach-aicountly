<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * reach_bot_plan_actuals: the achieved value per IST day and metric, written
 * by the daily planner job for the previous IST day. The last few days are
 * re-captured each run (REFRESH_DAYS) so Search Console's late days fill in
 * and a publish that was confirmed after midnight is counted on its day.
 * Job tables are pruned after 7–14 days; this table is the long-term record.
 */
class ActualsSnapshotService
{
    public const REFRESH_DAYS = 5;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?ActualsCollector $collector = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Snapshot $from…$to (both IST dates, never today or later).
     *
     * @return array{from:string, to:string, rows:int}
     */
    public function snapshot(string $from, string $to, ?\DateTimeImmutable $now = null): array
    {
        $yesterday = PlanPeriods::addDays(PlanPeriods::today($now), -1);
        $to        = min($to, $yesterday);
        if ($from > $to) {
            return ['from' => $from, 'to' => $to, 'rows' => 0];
        }
        $data = ($this->collector ?? new ActualsCollector($this->db))->collect($from, $to);
        $at   = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        $rows = 0;
        foreach ($data['series'] as $metric => $values) {
            foreach ($values as $date => $value) {
                $detail = array_filter(['note' => $data['notes'][$metric] ?? null, 'first_data_date' => $data['first_dates'][$metric] ?? null]);
                $this->db->query(
                    'INSERT INTO reach_bot_plan_actuals (metric_date, metric_key, value, detail_json, captured_at) VALUES (?, ?, ?, ?::jsonb, ?)
                     ON CONFLICT (metric_date, metric_key) DO UPDATE SET value = EXCLUDED.value, detail_json = EXCLUDED.detail_json, captured_at = EXCLUDED.captured_at',
                    [$date, $metric, $value, json_encode((object) $detail), $at]
                );
                $rows++;
            }
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /** The daily run: the previous IST day plus the REFRESH_DAYS before it. */
    public function snapshotRecent(?\DateTimeImmutable $now = null): array
    {
        $yesterday = PlanPeriods::addDays(PlanPeriods::today($now), -1);

        return $this->snapshot(PlanPeriods::addDays($yesterday, -(self::REFRESH_DAYS - 1)), $yesterday, $now);
    }

    /**
     * Stored actuals; dates with no stored row are read live (not written —
     * only the job writes) so a range that predates the first snapshot still
     * shows what happened.
     *
     * @return array<string, array<string, float|null>> metric => date => value
     */
    public function read(string $from, string $to): array
    {
        $dates = PlanPeriods::dates($from, $to);
        $out   = [];
        $have  = [];
        foreach ($this->db->query(
            'SELECT metric_date::text AS d, metric_key, value FROM reach_bot_plan_actuals WHERE metric_date BETWEEN ? AND ?',
            [$from, $to]
        )->getResultArray() as $r) {
            $out[$r['metric_key']][$r['d']] = $r['value'] === null ? null : (float) $r['value'];
            $have[$r['d']] = true;
        }
        $missing = array_values(array_filter($dates, static fn ($d) => ! isset($have[$d])));
        if ($missing !== []) {
            $live = ($this->collector ?? new ActualsCollector($this->db))->collect($missing[0], $missing[count($missing) - 1]);
            foreach ($live['series'] as $metric => $values) {
                foreach ($missing as $d) {
                    $out[$metric][$d] = $values[$d] ?? null;
                }
            }
        }
        foreach (PlanMetrics::keys() as $metric) {
            foreach ($dates as $d) {
                $out[$metric][$d] ??= null;
            }
            ksort($out[$metric]);
        }

        return $out;
    }
}

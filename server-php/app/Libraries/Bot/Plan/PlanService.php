<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Generates and reads the bot's plans for today, tomorrow, this/next week and
 * this/next month. regenerate() is what the scheduler calls (idempotent: an
 * unchanged plan is not re-versioned); view() is what the page reads.
 */
class PlanService
{
    private BaseConnection $db;
    private PlanStore $store;

    public function __construct(?BaseConnection $db = null, private ?PlanInputs $inputs = null, private ?ActualsCollector $collector = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->store = new PlanStore($this->db);
    }

    /**
     * @return array<string, array{plan_id:int, version:int, created:bool, start:string}>
     */
    public function regenerate(?\DateTimeImmutable $now = null): array
    {
        $inputs = $this->gather($now);
        $out    = [];
        foreach (PlanPeriods::PLAN_KEYS as $key) {
            $p    = PlanPeriods::plan($key, $now);
            $plan = PlanGenerator::generate($inputs, $p['type'], $p['start'], $p['end']);
            $out[$key] = $this->store->save($p['type'], $p['start'], $p['end'], $plan['items'], $plan['summary'], $now) + ['start' => $p['start']];
        }

        return $out;
    }

    /**
     * One period for the page / API: the stored plan (or, before the first
     * scheduled run, a preview computed now and marked stored=false), with
     * achieved-so-far for periods that have started.
     *
     * @return array<string, mixed>
     */
    public function view(string $key, ?\DateTimeImmutable $now = null): array
    {
        $p      = PlanPeriods::plan($key, $now);
        $today  = PlanPeriods::today($now);
        $stored = $this->store->latest($p['type'], $p['start']);

        if ($stored !== null) {
            $items   = $stored['items'];
            $summary = $stored['summary_json'];
            $meta    = ['stored' => true, 'plan_id' => (int) $stored['id'], 'version' => (int) $stored['version'],
                'generated_at' => (string) $stored['generated_at'], 'last_checked_at' => (string) $stored['last_checked_at'], 'method_version' => (string) $stored['method_version']];
        } else {
            $plan    = PlanGenerator::generate($this->gather($now), $p['type'], $p['start'], $p['end']);
            $items   = array_column($plan['items'], null, 'metric_key');
            $summary = $plan['summary'];
            $meta    = ['stored' => false, 'plan_id' => null, 'version' => null, 'generated_at' => null, 'last_checked_at' => null, 'method_version' => PlanGenerator::METHOD_VERSION,
                'note' => 'Preview computed now — the scheduled planner has not stored this period yet (it runs hourly).'];
        }

        // Achieved so far, for periods that have started (today counts as partial).
        $started  = $p['start'] <= $today;
        $elapsed  = $started ? PlanPeriods::days($p['start'], min($p['end'], $today)) : 0;
        $achieved = [];
        $notes    = [];
        if ($started) {
            $actual   = ($this->collector ?? new ActualsCollector($this->db))->collect($p['start'], min($p['end'], $today));
            $notes    = $actual['notes'];
            foreach ($actual['series'] as $metric => $values) {
                $known = array_filter($values, static fn ($v) => $v !== null);
                $achieved[$metric] = $known === [] ? null : array_sum($known);
            }
        }

        $metrics = PlanMetrics::all();
        $rows    = [];
        foreach ($metrics as $metric => $m) {
            $item = $items[$metric] ?? null;
            if ($item === null) {
                continue;
            }
            $target   = $item['target_value'];
            $expected = ($started && $target !== null) ? round((float) $target * $elapsed / max(1, $p['days']), 2) : null;
            $got      = $started ? ($achieved[$metric] ?? null) : null;
            $rows[]   = $item + [
                'label'             => $m['label'],
                'achieved_to_date'  => $got,
                'expected_to_date'  => $expected,
                'pct_of_expected'   => ($expected !== null && $expected > 0 && $got !== null) ? (int) round($got / $expected * 100) : null,
                'adjusted'          => ($item['source'] ?? '') === 'self_tune',
                'actuals_note'      => $notes[$metric] ?? null,
            ];
        }

        return [
            'period'            => $p + ['elapsed_days' => $elapsed, 'partial_today' => $started && $p['end'] >= $today],
            'plan'              => $meta,
            'versions'          => $stored !== null ? $this->store->versions($p['type'], $p['start']) : [],
            'items'             => $rows,
            'groups'            => PlanMetrics::GROUPS,
            'scheduled'         => $summary['scheduled'] ?? [],
            'routines'          => $summary['routines'] ?? PlanInputs::ROUTINE_CAPACITY,
            'overrides'         => PlanInputs::overrides($this->db),
            'self_tune_enabled' => (bool) ($summary['self_tune_enabled'] ?? true),
            'timezone'          => PlanPeriods::TZ,
        ];
    }

    /** @return array<string, mixed> */
    private function gather(?\DateTimeImmutable $now): array
    {
        $today   = PlanPeriods::today($now);
        $horizon = PlanPeriods::plan('next_month', $now)['end'];

        return ($this->inputs ?? new PlanInputs($this->db, $this->collector))->gather($today, $horizon);
    }
}

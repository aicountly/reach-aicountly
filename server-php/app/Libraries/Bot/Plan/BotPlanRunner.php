<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the scheduler runs (job reach.bot_plan):
 *
 *   mode=daily    (01:00 IST, once per IST date)
 *     1. snapshot actuals for yesterday and the days before it that may
 *        still change (ActualsSnapshotService::REFRESH_DAYS)
 *     2. review the 7 days ending yesterday: variance, root causes, lessons,
 *        recommendations, topic mix → reach_bot_performance_reviews
 *     3. self-tune within bounds (SelfTuner), logged
 *     4. regenerate every plan period so today's plan carries the changes
 *   mode=refresh  (hourly) step 4 only — picks up a changed setting or a new
 *                 schedule; an unchanged plan is not re-versioned.
 *
 * Every step is idempotent for its date.
 */
class BotPlanRunner
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private ?LagSignals $signals = null,
        private ?SelfTuner $tuner = null,
        private ?ActualsCollector $collector = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function run(string $mode = 'daily', ?\DateTimeImmutable $now = null, bool $tune = true): array
    {
        $out = ['mode' => $mode, 'today' => PlanPeriods::today($now)];
        if ($mode === 'daily') {
            $yesterday = PlanPeriods::addDays($out['today'], -1);
            $out['actuals'] = (new ActualsSnapshotService($this->db, $this->collector))->snapshotRecent($now);

            $signals = $this->signals ?? new LagSignals($this->db);
            $perf    = new PerformanceService($this->db, new ActualsSnapshotService($this->db, $this->collector), $signals);
            $previous = $perf->latestReview();
            $week     = $perf->compute(PlanPeriods::addDays($yesterday, -6), $yesterday);
            $review   = $perf->review($yesterday, $week);

            if ($tune) {
                $sig  = $signals->gather(PlanPeriods::addDays($yesterday, -6), $yesterday);
                $tuned = ($this->tuner ?? new SelfTuner($this->db))->run($yesterday, $week, $sig, $review['topic_mix'] ?? null, $previous['topic_mix'] ?? null, $now);
                $review['self_tune'] = ['enabled' => $tuned['enabled'], 'applied' => $tuned['applied'], 'skipped' => $tuned['skipped'],
                    'actions' => array_map(static fn ($a) => array_intersect_key($a, array_flip(['action', 'metric_key', 'reason', 'applied', 'skipped_reason', 'before', 'after'])), $tuned['actions'])];
                $out['self_tune'] = ['enabled' => $tuned['enabled'], 'applied' => $tuned['applied'], 'skipped' => $tuned['skipped']];
            }
            $perf->storeReview($review);
            $out['review'] = ['date' => $yesterday, 'top_lags' => array_column($review['top_lags'], 'metric_key')];
        }

        $plans = (new PlanService($this->db, null, $this->collector))->regenerate($now);
        $out['plans'] = array_map(static fn ($p) => ['version' => $p['version'], 'created' => $p['created'], 'start' => $p['start']], $plans);

        return $out;
    }
}

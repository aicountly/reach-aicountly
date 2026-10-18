<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Bot;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Bot\Plan\PerformanceService;
use App\Libraries\Bot\Plan\PlanPeriods;
use App\Libraries\Bot\Plan\PlanService;
use App\Libraries\Bot\Plan\SelfTuner;

/**
 * Bot → Plan & Performance (permission bot.view).
 *
 *   GET v1/bot/plan?period=today|tomorrow|this_week|next_week|this_month|next_month
 *   GET v1/bot/performance?range=yesterday|last_7_days|last_30_days|this_week|last_week|this_month|last_month
 */
class BotPlanController extends BaseApiController
{
    public function plan()
    {
        $period = (string) ($this->request->getGet('period') ?: 'today');
        if (! in_array($period, PlanPeriods::PLAN_KEYS, true)) {
            return $this->fail('period must be one of: ' . implode(', ', PlanPeriods::PLAN_KEYS), 422);
        }

        return $this->ok((new PlanService())->view($period) + ['periods' => PlanPeriods::PLAN_KEYS]);
    }

    public function performance()
    {
        $range = (string) ($this->request->getGet('range') ?: 'last_7_days');
        if (! in_array($range, PlanPeriods::RANGE_KEYS, true)) {
            return $this->fail('range must be one of: ' . implode(', ', PlanPeriods::RANGE_KEYS), 422);
        }
        $svc    = new PerformanceService();
        $perf   = $svc->performance($range);
        $review = $svc->latestReview();

        return $this->ok($perf + [
            'ranges'            => PlanPeriods::RANGE_KEYS,
            'self_tune'         => ['enabled' => SelfTuner::enabled(), 'bounds' => [
                'min_fraction_of_base' => SelfTuner::MIN_FRACTION, 'lag_days_before_lowering' => SelfTuner::LAG_DAYS,
                'structural_days' => SelfTuner::STRUCTURAL_DAYS, 'restore_after_days_met' => SelfTuner::RESTORE_DAYS,
                'cooldown_days' => SelfTuner::COOLDOWN_DAYS, 'max_actions_per_run' => SelfTuner::MAX_ACTIONS,
                'max_requeue_per_run' => SelfTuner::MAX_REQUEUE, 'max_job_retries_per_run' => SelfTuner::MAX_RETRY,
            ], 'log' => SelfTuner::recentLog(null, 50)],
            'topic_mix'         => $review['topic_mix'] ?? null,
            'latest_review'     => $review === null ? null : ['review_date' => $review['review_date'] ?? null, 'generated_at' => $review['generated_at'] ?? null],
            'timezone'          => PlanPeriods::TZ,
        ]);
    }
}

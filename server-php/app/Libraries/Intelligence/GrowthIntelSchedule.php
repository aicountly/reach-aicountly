<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;

/**
 * Stream H timetable, ticked by `reach:schedule` every minute:
 *
 *  - 05:00 IST daily  — AI-visibility pass (finishes before the 08:00 IST report)
 *  - 16:00 IST Sunday — weekly AI Insights report (ready before the
 *                       reach-growth-strategy routine at 18:00 IST)
 *
 * Each job carries a per-period idempotency key and is skipped when any job
 * with that key exists in any state — JobService alone would re-queue a
 * finished job every minute of the hour.
 */
final class GrowthIntelSchedule
{
    public const VISIBILITY_DAILY = 'reach.ai_visibility_daily';
    public const INSIGHTS_RUN     = 'reach.ai_insights_run';

    /**
     * @return list<array{type: string, payload: array<string, mixed>, key: string}>
     */
    public static function plannedJobs(\DateTimeImmutable $now, ?bool $visibilityOn = null, ?bool $insightsOn = null): array
    {
        $ist  = $now->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $date = $ist->format('Y-m-d');
        $hour = (int) $ist->format('H');
        $jobs = [];

        if ($hour === 5 && ($visibilityOn ?? (bool) ReachConfig::get('visibility.enabled', true))) {
            $jobs[] = ['type' => self::VISIBILITY_DAILY, 'payload' => ['trigger' => 'scheduled', 'date' => $date], 'key' => "ai-visibility-daily-{$date}"];
        }
        if ($hour === 16 && $ist->format('N') === '7' && ($insightsOn ?? (bool) ReachConfig::get('insights.weekly.enabled', true))) {
            $jobs[] = ['type' => self::INSIGHTS_RUN, 'payload' => ['trigger' => 'scheduled', 'date' => $date], 'key' => "ai-insights-weekly-{$date}"];
        }

        return $jobs;
    }

    /**
     * Enqueue whatever is due now and not already queued/run.
     *
     * @return list<int> job ids enqueued
     */
    public static function dispatch(mixed $jobService, BaseConnection $db, ?\DateTimeImmutable $now = null): array
    {
        $ids = [];
        foreach (self::plannedJobs($now ?? new \DateTimeImmutable('now')) as $job) {
            $exists = $db->table('reach_jobs')
                ->groupStart()
                    ->where('idempotency_key', $job['key'])
                    ->orLike('idempotency_key', $job['key'] . ':retired:', 'after')
                ->groupEnd()
                ->countAllResults();
            if ($exists > 0) {
                continue;
            }
            $ids[] = (int) $jobService->enqueue($job['type'], $job['payload'], [
                'queue'               => 'default',
                'idempotency_key'     => $job['key'],
                'max_attempts'        => 1,
                'enqueued_actor_type' => 'system',
            ]);
        }

        return $ids;
    }
}

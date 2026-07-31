<?php

namespace App\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Phase 5 — Community Analytics Reconciliation Job.
 *
 * Job type key: reach.community_analytics_reconciliation
 *
 * Payload: { "date": "YYYY-MM-DD" (optional, defaults to yesterday) }
 *
 * Aggregates a single day into reach_community_analytics_cache.
 *
 * The cache table is keyed by (metric_key, dimension, period_start, period_end)
 * with a numeric `value` — this job writes exactly that shape. An earlier
 * version queried cache_date/event_count/answer_external_id/is_validated, none
 * of which exist in any migration, so every run failed.
 */
class CommunityAnalyticsReconciliationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $date        = (string) ($payload['date'] ?? $payload['cache_date'] ?? date('Y-m-d', strtotime('-1 day')));
        $periodStart = $date . ' 00:00:00';
        $periodEnd   = $date . ' 23:59:59';

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("CommunityAnalyticsReconciliationJob: invalid date '{$date}'.");
        }

        $db      = db_connect();
        $written = 0;

        // 1. Genuine engagement per answer and event type. Only validated,
        //    non-bot-filtered events are counted.
        $engagement = $db->query(
            'SELECT e.answer_id, e.event_type, COUNT(*) AS total
               FROM reach_community_engagement_events e
              WHERE e.validated = TRUE
                AND e.bot_filtered = FALSE
                AND e.answer_id IS NOT NULL
                AND e.event_timestamp >= ?
                AND e.event_timestamp <= ?
              GROUP BY e.answer_id, e.event_type',
            [$periodStart, $periodEnd]
        )->getResultArray();

        foreach ($engagement as $row) {
            $written += $this->upsert(
                $db,
                'engagement.' . $row['event_type'],
                'answer:' . (int) $row['answer_id'],
                $periodStart,
                $periodEnd,
                (float) $row['total'],
                ['answer_id' => (int) $row['answer_id'], 'event_type' => $row['event_type']]
            );
        }

        // 2. Question funnel counts for the day, by status.
        $questions = $db->query(
            'SELECT status, COUNT(*) AS total
               FROM reach_community_questions
              WHERE created_at >= ? AND created_at <= ?
              GROUP BY status',
            [$periodStart, $periodEnd]
        )->getResultArray();

        foreach ($questions as $row) {
            $written += $this->upsert(
                $db,
                'questions.by_status',
                (string) $row['status'],
                $periodStart,
                $periodEnd,
                (float) $row['total'],
                ['status' => $row['status']]
            );
        }

        // 3. Answer funnel counts for the day, by status and risk tier.
        $answers = $db->query(
            'SELECT status, risk_tier, COUNT(*) AS total
               FROM reach_community_official_answers
              WHERE created_at >= ? AND created_at <= ?
              GROUP BY status, risk_tier',
            [$periodStart, $periodEnd]
        )->getResultArray();

        foreach ($answers as $row) {
            $written += $this->upsert(
                $db,
                'answers.by_status',
                $row['status'] . '|tier:' . (int) $row['risk_tier'],
                $periodStart,
                $periodEnd,
                (float) $row['total'],
                ['status' => $row['status'], 'risk_tier' => (int) $row['risk_tier']]
            );
        }

        // 4. Publication outcomes for the day.
        $deployments = $db->query(
            'SELECT status, COUNT(*) AS total
               FROM reach_community_deployments
              WHERE created_at >= ? AND created_at <= ?
              GROUP BY status',
            [$periodStart, $periodEnd]
        )->getResultArray();

        foreach ($deployments as $row) {
            $written += $this->upsert(
                $db,
                'publications.by_status',
                (string) $row['status'],
                $periodStart,
                $periodEnd,
                (float) $row['total'],
                ['status' => $row['status']]
            );
        }

        // 5. Human review backlog and stale-answer backlog as of this run.
        $backlog = $db->query(
            "SELECT
                COUNT(*) FILTER (WHERE status IN ('editorial_review','professional_review')) AS review_backlog,
                COUNT(*) FILTER (WHERE freshness_deadline IS NOT NULL AND freshness_deadline < CURRENT_DATE
                                   AND status = 'published')                                 AS stale_answers,
                COUNT(*) FILTER (WHERE reverification_state = 'due')                         AS reverification_backlog
               FROM reach_community_official_answers"
        )->getRowArray() ?? [];

        foreach (['review_backlog', 'stale_answers', 'reverification_backlog'] as $metric) {
            $written += $this->upsert(
                $db,
                'backlog.' . $metric,
                'global',
                $periodStart,
                $periodEnd,
                (float) ($backlog[$metric] ?? 0),
                []
            );
        }

        // 6. Bot versus human contribution ratio for published answers.
        $contribution = $db->query(
            "SELECT
                COUNT(*) FILTER (WHERE ai_assisted = TRUE)  AS bot_authored,
                COUNT(*) FILTER (WHERE ai_assisted = FALSE) AS human_authored
               FROM reach_community_official_answers
              WHERE status = 'published'"
        )->getRowArray() ?? [];

        foreach (['bot_authored', 'human_authored'] as $metric) {
            $written += $this->upsert(
                $db,
                'contribution.' . $metric,
                'global',
                $periodStart,
                $periodEnd,
                (float) ($contribution[$metric] ?? 0),
                []
            );
        }

        AuditLogger::record(AuditLogger::COMMUNITY_ANALYTICS_RECONCILED, [
            'date'           => $date,
            'metrics_written' => $written,
        ], $ctx->enqueuedByUserId);

        return ['ok' => true, 'date' => $date, 'metrics_written' => $written];
    }

    /**
     * Upsert against the real cache key: (metric_key, dimension, period_start, period_end).
     */
    private function upsert(
        $db,
        string $metricKey,
        string $dimension,
        string $periodStart,
        string $periodEnd,
        float $value,
        array $meta
    ): int {
        $db->query(
            'INSERT INTO reach_community_analytics_cache
                (metric_key, dimension, period_start, period_end, value, meta, computed_at)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, NOW())
             ON CONFLICT (metric_key, dimension, period_start, period_end) DO UPDATE SET
                value       = EXCLUDED.value,
                meta        = EXCLUDED.meta,
                computed_at = NOW()',
            [$metricKey, $dimension, $periodStart, $periodEnd, $value, json_encode($meta)]
        );

        return 1;
    }
}

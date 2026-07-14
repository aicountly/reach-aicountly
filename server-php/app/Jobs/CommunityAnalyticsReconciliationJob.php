<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\AuditLogger;

/**
 * Phase 5 — Community Analytics Reconciliation Job.
 *
 * Job type key: reach.community_analytics_reconciliation
 *
 * Payload: { "cache_date": "YYYY-MM-DD" (optional, defaults to yesterday) }
 *
 * Refreshes the reach_community_analytics_cache table with aggregated
 * genuine engagement counts for a given day. Only validated events
 * (is_validated = TRUE) are counted.
 */
class CommunityAnalyticsReconciliationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $cacheDate = $payload['cache_date'] ?? date('Y-m-d', strtotime('-1 day'));

        $db = db_connect();

        // Aggregate genuine engagement by answer + event type for the day
        $rows = $db->query("
            SELECT
                ea.answer_id,
                e.event_type,
                COUNT(*) AS event_count
            FROM reach_community_engagement_events e
            JOIN reach_community_official_answers ea ON ea.external_id = e.answer_external_id
            WHERE e.is_validated = TRUE
              AND DATE(e.created_at) = :cacheDate
            GROUP BY ea.answer_id, e.event_type
        ", [':cacheDate' => $cacheDate])->getResultArray();

        foreach ($rows as $row) {
            $db->query("
                INSERT INTO reach_community_analytics_cache
                    (cache_date, answer_id, event_type, event_count, last_reconciled_at)
                VALUES (:cacheDate, :answerId, :eventType, :eventCount, NOW())
                ON CONFLICT (cache_date, answer_id, event_type) DO UPDATE SET
                    event_count         = EXCLUDED.event_count,
                    last_reconciled_at  = NOW()
            ", [
                ':cacheDate'  => $cacheDate,
                ':answerId'   => (int) $row['answer_id'],
                ':eventType'  => $row['event_type'],
                ':eventCount' => (int) $row['event_count'],
            ]);
        }

        // Also compute per-space aggregates
        $spaceRows = $db->query("
            SELECT
                q.space_id,
                COUNT(DISTINCT a.id)  AS total_answers,
                COUNT(DISTINCT CASE WHEN a.status = 'published' THEN a.id END) AS published_answers,
                COUNT(DISTINCT aq.id) AS total_questions
            FROM reach_community_official_answers a
            JOIN reach_community_questions aq ON aq.id = a.question_id
            JOIN reach_community_spaces q ON q.id = aq.space_id
            WHERE a.status != 'withdrawn'
            GROUP BY q.space_id
        ")->getResultArray();

        foreach ($spaceRows as $row) {
            $db->query("
                INSERT INTO reach_community_analytics_cache
                    (cache_date, space_id, event_type, event_count, last_reconciled_at)
                VALUES (:cacheDate, :spaceId, 'space_summary', :cnt, NOW())
                ON CONFLICT (cache_date, space_id, event_type) DO UPDATE SET
                    event_count        = EXCLUDED.event_count,
                    last_reconciled_at = NOW()
            ", [
                ':cacheDate' => $cacheDate,
                ':spaceId'   => (int) $row['space_id'],
                ':cnt'       => (int) $row['published_answers'],
            ]);
        }

        AuditLogger::log(AuditLogger::COMMUNITY_ANALYTICS_RECONCILED, [
            'cache_date'  => $cacheDate,
            'rows_synced' => count($rows),
        ]);

        return ['ok' => true, 'cache_date' => $cacheDate, 'rows_synced' => count($rows)];
    }
}

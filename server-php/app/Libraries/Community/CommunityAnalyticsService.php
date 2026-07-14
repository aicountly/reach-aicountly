<?php

namespace App\Libraries\Community;

/**
 * Phase 5 — Community Analytics Service.
 *
 * Reads aggregated data from reach_community_analytics_cache and the
 * underlying tables to provide analytics summaries for the operator UI.
 *
 * Only genuine, validated engagement events are included.
 */
class CommunityAnalyticsService
{
    /**
     * Return high-level overview metrics.
     *
     * @return array{
     *   questions_by_status: array<string,int>,
     *   answers_by_status: array<string,int>,
     *   published_answers: int,
     *   pending_approval: int,
     *   open_moderation_flags: int
     * }
     */
    public function overview(): array
    {
        $db = db_connect();

        $questionStats = $db->query("
            SELECT status, COUNT(*) AS cnt
            FROM reach_community_questions
            GROUP BY status
        ")->getResultArray();

        $answerStats = $db->query("
            SELECT status, COUNT(*) AS cnt
            FROM reach_community_official_answers
            GROUP BY status
        ")->getResultArray();

        return [
            'questions_by_status'   => array_column($questionStats, 'cnt', 'status'),
            'answers_by_status'     => array_column($answerStats, 'cnt', 'status'),
            'published_answers'     => (int) $db->table('reach_community_official_answers')->where('status', 'published')->countAllResults(),
            'pending_approval'      => (int) $db->table('reach_community_official_answers')->where('status', 'pending_approval')->countAllResults(),
            'open_moderation_flags' => (int) $db->table('reach_community_moderation_findings')->where('status', 'open')->countAllResults(),
        ];
    }

    /**
     * Return genuine engagement event counts over the given number of days.
     *
     * @return list<array{day: string, event_type: string, cnt: int}>
     */
    public function engagement(int $days = 30): array
    {
        $days = min($days, 90);
        $db   = db_connect();
        return $db->query("
            SELECT DATE(created_at) AS day, event_type, COUNT(*) AS cnt
            FROM reach_community_engagement_events
            WHERE is_validated = TRUE
              AND created_at >= NOW() - INTERVAL '{$days} days'
            GROUP BY DATE(created_at), event_type
            ORDER BY day ASC
        ")->getResultArray();
    }

    /**
     * Return top grounding source usage.
     *
     * @return list<array{source_type: string, source_id: int, usage_count: int}>
     */
    public function topGroundingSources(int $limit = 50): array
    {
        $db = db_connect();
        return $db->query("
            SELECT source_type, source_id, COUNT(DISTINCT answer_version_id) AS usage_count
            FROM reach_community_source_coverage
            GROUP BY source_type, source_id
            ORDER BY usage_count DESC
            LIMIT {$limit}
        ")->getResultArray();
    }

    /**
     * Return cached analytics rows for the last N days.
     *
     * @return list<array<string,mixed>>
     */
    public function cachedRows(int $days = 30): array
    {
        $db = db_connect();
        return $db->table('reach_community_analytics_cache')
            ->where('cache_date >=', date('Y-m-d', strtotime("-{$days} days")))
            ->orderBy('cache_date', 'DESC')
            ->get()->getResultArray();
    }
}

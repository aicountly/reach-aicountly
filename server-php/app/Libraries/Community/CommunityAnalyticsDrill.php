<?php

declare(strict_types=1);

namespace App\Libraries\Community;

use CodeIgniter\Database\BaseConnection;

/**
 * The records behind Community → Analytics' grouped rows:
 *
 *   - engagement event-type (and day) counts → the validated events, each
 *     with the question / official answer it was recorded on;
 *   - "Top grounding sources" rows → the answer versions grounded on that
 *     source (reach_community_source_coverage), with the claims it backs.
 *
 * Each list uses the predicate its count uses, so the totals match.
 */
final class CommunityAnalyticsDrill
{
    /** reach_community_engagement_events.event_type CHECK. */
    public const EVENT_TYPES = ['page_view', 'helpful', 'not_helpful', 'reply', 'report', 'click'];

    /** reach_community_source_coverage.source_type CHECK. */
    public const SOURCE_TYPES = ['kb_article', 'blog', 'product_doc', 'release_note', 'policy', 'feature_record', 'marketing_knowledge', 'external'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** The analytics window: 1–90 days, 30 when unset. */
    public static function days(mixed $value): int
    {
        $days = (int) ($value ?? 30);

        return max(1, min($days ?: 30, 90));
    }

    /**
     * What GET v1/community/analytics/engagement counts: validated events in
     * the last $days days. [sql, binds] on the events table aliased `$alias`.
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function engagementWindow(int $days, string $alias = 'e'): array
    {
        $p = $alias === '' ? '' : $alias . '.';

        return ["{$p}validated = TRUE AND {$p}event_timestamp >= NOW() - (? * INTERVAL '1 day')", [$days]];
    }

    /**
     * @param array{event_type?: string, day?: string, days?: mixed} $filters
     * @return array{items: list<array<string, mixed>>, total: int, days: int}
     */
    public function events(array $filters, int $limit, int $offset): array
    {
        $days = self::days($filters['days'] ?? null);
        [$sql, $binds] = self::engagementWindow($days);
        $where = [$sql];

        $type = (string) ($filters['event_type'] ?? '');
        if (in_array($type, self::EVENT_TYPES, true)) {
            $where[] = 'e.event_type = ?';
            $binds[] = $type;
        }
        $day = self::date((string) ($filters['day'] ?? ''));
        if ($day !== null) {
            // DATE(event_timestamp), as the per-day counts group it.
            $where[] = 'DATE(e.event_timestamp) = ?';
            $binds[] = $day;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) ($this->db->query(
            "SELECT COUNT(*) AS n FROM reach_community_engagement_events e WHERE {$whereSql}",
            $binds,
        )->getRowArray()['n'] ?? 0);

        // An event names its answer, its question, or both; the question of
        // an answer-only event is the answer's question.
        $rows = $this->db->query(
            "SELECT e.id, e.uuid, e.event_type, e.event_timestamp, e.source, e.bot_filtered,
                    a.uuid AS answer_uuid, a.status AS answer_status,
                    COALESCE(q.uuid, aq.uuid) AS question_uuid,
                    COALESCE(q.title, aq.title) AS question_title
               FROM reach_community_engagement_events e
               LEFT JOIN reach_community_official_answers a ON a.id = e.answer_id
               LEFT JOIN reach_community_questions q  ON q.id = e.question_id
               LEFT JOIN reach_community_questions aq ON aq.id = a.question_id
              WHERE {$whereSql}
              ORDER BY e.event_timestamp DESC, e.id DESC
              LIMIT ? OFFSET ?",
            [...$binds, $limit, $offset],
        )->getResultArray();

        return ['items' => $rows, 'total' => $total, 'days' => $days];
    }

    /**
     * The answer versions grounded on one source — what the coverage row's
     * usage_count (COUNT(DISTINCT answer_version_id)) counted. $sourceId
     * null is the group of rows with no source_id.
     *
     * @return array{items: list<array<string, mixed>>, total: int, source: ?array<string, mixed>}
     */
    public function groundedAnswers(string $sourceType, ?int $sourceId, int $limit, int $offset): array
    {
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            return ['items' => [], 'total' => 0, 'source' => null];
        }
        $where = 'c.source_type = ? AND ' . ($sourceId === null ? 'c.source_id IS NULL' : 'c.source_id = ?');
        $binds = $sourceId === null ? [$sourceType] : [$sourceType, $sourceId];

        $total = (int) ($this->db->query(
            "SELECT COUNT(DISTINCT c.answer_version_id) AS n FROM reach_community_source_coverage c WHERE {$where}",
            $binds,
        )->getRowArray()['n'] ?? 0);

        $source = $this->db->query(
            "SELECT MAX(c.source_title) AS title, MAX(c.source_url) AS url, MAX(c.source_version) AS version
               FROM reach_community_source_coverage c WHERE {$where}",
            $binds,
        )->getRowArray();

        $rows = $this->db->query(
            "SELECT v.id AS answer_version_id, v.version_number, v.created_at AS version_created_at,
                    a.uuid AS answer_uuid, a.status AS answer_status,
                    q.uuid AS question_uuid, q.title AS question_title,
                    json_agg(json_build_object('claim', c.claim_reference, 'coverage_status', c.coverage_status) ORDER BY c.id) AS claims
               FROM reach_community_source_coverage c
               JOIN reach_community_answer_versions v ON v.id = c.answer_version_id
               JOIN reach_community_official_answers a ON a.id = v.answer_id
               LEFT JOIN reach_community_questions q ON q.id = a.question_id
              WHERE {$where}
              GROUP BY v.id, v.version_number, v.created_at, a.uuid, a.status, q.uuid, q.title
              ORDER BY v.created_at DESC, v.id DESC
              LIMIT ? OFFSET ?",
            [...$binds, $limit, $offset],
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['claims'] = json_decode((string) ($r['claims'] ?? '[]'), true) ?: [];
        }
        unset($r);

        return ['items' => $rows, 'total' => $total, 'source' => $total > 0 ? $source : null];
    }

    private static function date(string $value): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }
}

<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityQuestionStatus;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Picks the questions community:agents-run works on next.
 *
 * Selection only: whether an action is permitted, inside the window and under
 * its daily cap is still decided by CommunityOperationalAgentService::dispatch().
 */
class CommunityAgentWorkSelector
{
    /**
     * Upper bound on rows read per selection. Policy is applied in PHP so the
     * retry schedule lives in one place, and this keeps that read bounded.
     */
    private const SCAN_LIMIT = 500;

    /**
     * Questions whose intake-time processing never completed: no
     * classification was ever stored, so the personal-data screen has not run.
     *
     * @return list<int>
     */
    public function questionsAwaitingProcessing(int $limit): array
    {
        $rows = $this->db()->query(
            'SELECT q.id
               FROM reach_community_questions q
              WHERE NOT EXISTS (
                        SELECT 1 FROM reach_community_question_classifications c WHERE c.question_id = q.id
                    )
                AND q.status NOT IN (?, ?)
              ORDER BY q.id ASC
              LIMIT ?',
            [CommunityQuestionStatus::Archived->value, CommunityQuestionStatus::DuplicateMerged->value, $limit]
        )->getResultArray();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * What the expert desks should draft next, in triage order: screened
     * questions that were never drafted, and first drafts whose generation
     * failed and are due another automatic attempt.
     *
     * A retry names the desk that owns the answer; a fresh draft names none
     * and is routed by category. Questions that have used up their automatic
     * attempts are counted, not returned — they are waiting on a human.
     *
     * @return array{
     *   candidates: list<array{question_id: int, question_uuid: string, category: string, answer_uuid: ?string, owner_slug: ?string, attempt: int}>,
     *   exhausted: int
     * }
     */
    public function draftCandidates(int $limit): array
    {
        $db        = $this->db();
        $retryable = implode(',', array_map(
            static fn (CommunityAnswerStatus $status): string => $db->escape($status->value),
            OfficialAnswerLifecycleService::GENERATION_RETRYABLE_STATUSES
        ));

        // Failed attempts are read from the agent run log, where dispatch()
        // records every draft_answer failure against the question's UUID.
        $rows = $db->query(
            "SELECT q.id, q.uuid, q.category,
                    a.uuid AS answer_uuid, owner.slug AS owner_slug,
                    COALESCE(f.failed_attempts, 0) AS failed_attempts,
                    COALESCE(f.seconds_since_last_failure, 0) AS seconds_since_last_failure
               FROM reach_community_questions q
               LEFT JOIN reach_community_official_answers a ON a.question_id = q.id
               LEFT JOIN reach_community_official_identities owner ON owner.id = a.identity_id
               LEFT JOIN LATERAL (
                    SELECT COUNT(*) AS failed_attempts,
                           EXTRACT(EPOCH FROM (NOW() - MAX(r.created_at)))::bigint AS seconds_since_last_failure
                      FROM reach_community_agent_runs r
                     WHERE r.action = 'draft_answer'
                       AND r.outcome = 'failed'
                       AND r.target_external_ref = q.uuid::text
               ) f ON TRUE
              WHERE q.moderation_state = 'clean'
                AND q.personal_data_detected = FALSE
                AND EXISTS (
                        SELECT 1 FROM reach_community_question_classifications c WHERE c.question_id = q.id
                    )
                AND (
                        (a.id IS NULL AND q.status IN ('intake', 'triaged'))
                     OR (q.status = 'draft_requested'
                         AND a.status IN ({$retryable})
                         AND NOT EXISTS (
                                 SELECT 1 FROM reach_community_answer_versions v WHERE v.answer_id = a.id
                             )
                         AND owner.is_active = TRUE
                         AND owner.operational_role = 'expert_answer_assistant')
                    )
              ORDER BY q.triage_score DESC, q.id ASC
              LIMIT " . self::SCAN_LIMIT
        )->getResultArray();

        $candidates = [];
        $exhausted  = 0;
        $seen       = [];

        foreach ($rows as $row) {
            $questionId = (int) $row['id'];
            if (isset($seen[$questionId])) {
                continue;
            }
            $seen[$questionId] = true;

            $failed = (int) $row['failed_attempts'];
            if ($failed >= CommunityOperationalAgentService::MAX_AUTOMATIC_DRAFT_ATTEMPTS) {
                $exhausted++;
                continue;
            }
            if (count($candidates) >= $limit
                || ! CommunityOperationalAgentService::isDraftRetryDue($failed, (int) $row['seconds_since_last_failure'])
            ) {
                continue;
            }

            $candidates[] = [
                'question_id'   => $questionId,
                'question_uuid' => (string) $row['uuid'],
                'category'      => (string) ($row['category'] ?? ''),
                'answer_uuid'   => $row['answer_uuid'] !== null ? (string) $row['answer_uuid'] : null,
                'owner_slug'    => $row['owner_slug'] !== null ? (string) $row['owner_slug'] : null,
                'attempt'       => $failed + 1,
            ];
        }

        return ['candidates' => $candidates, 'exhausted' => $exhausted];
    }

    private function db(): BaseConnection
    {
        return Database::connect();
    }
}

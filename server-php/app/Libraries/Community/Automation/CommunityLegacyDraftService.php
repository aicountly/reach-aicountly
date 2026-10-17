<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\AuditLogger;
use App\Libraries\Community\OfficialAnswerRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Answers the old in-Reach drafting left stranded.
 *
 * community:agents-run used to create an answer and ask an LLM for a draft;
 * nothing moved a draft past draft_generated, and nobody approves the human
 * review queues any more. Those answers block their question: a question
 * holds one live answer, so the routine cannot answer it either. Clearing
 * archives them (nothing is deleted, nothing was public), which frees each
 * question to appear in community-plan's open_questions again.
 */
class CommunityLegacyDraftService
{
    /** Statuses nothing automatic moves an answer out of any more. */
    public const STUCK_STATUSES = [
        'draft_requested', 'generating', 'draft_generated', 'validation_failed',
        'changes_requested', 'moderation_required', 'editorial_review', 'professional_review',
    ];

    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly OfficialAnswerRepository $answers = new OfficialAnswerRepository(),
    ) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Stuck answers not owned by a routine submission that is still in flight.
     *
     * @return list<array{answer_id: string, question_id: string, question: string, desk: string, status: string, versions: int, age_hours: int}>
     */
    public function list(int $limit = 500): array
    {
        $rows = $this->db->query(
            "SELECT a.id, a.uuid, a.status, a.updated_at, q.uuid AS question_uuid, q.title, i.slug AS desk,
                    (SELECT COUNT(*) FROM reach_community_answer_versions v WHERE v.answer_id = a.id) AS versions,
                    EXTRACT(EPOCH FROM (NOW() - a.updated_at))::bigint AS age_seconds
               FROM reach_community_official_answers a
               JOIN reach_community_questions q ON q.id = a.question_id
               LEFT JOIN reach_community_official_identities i ON i.id = a.identity_id
              WHERE a.status IN ('" . implode("','", self::STUCK_STATUSES) . "')
                AND NOT EXISTS (
                        SELECT 1 FROM reach_community_automation_submissions s
                         WHERE s.answer_id = a.id AND s.status IN ('pending', 'approved')
                    )
              ORDER BY a.id ASC
              LIMIT ?",
            [$limit]
        )->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'answer_id'   => (string) $r['uuid'],
            'question_id' => (string) $r['question_uuid'],
            'question'    => (string) $r['title'],
            'desk'        => (string) ($r['desk'] ?? ''),
            'status'      => (string) $r['status'],
            'versions'    => (int) $r['versions'],
            'age_hours'   => intdiv((int) $r['age_seconds'], 3600),
        ], $rows);
    }

    /**
     * Archive stuck answers (all of them, or those older than $minAgeHours, or
     * the one named), returning what was (or, on a dry run, would be) cleared.
     *
     * @return array{cleared: int, dry_run: bool, items: list<array<string, mixed>>}
     */
    public function clear(bool $dryRun = true, int $minAgeHours = 0, ?string $answerUuid = null): array
    {
        $items = array_values(array_filter(
            $this->list(),
            static fn (array $row): bool => $row['age_hours'] >= $minAgeHours
                && ($answerUuid === null || $row['answer_id'] === $answerUuid)
        ));

        if ($dryRun) {
            return ['cleared' => 0, 'dry_run' => true, 'items' => $items];
        }

        $cleared = 0;
        foreach ($items as &$item) {
            try {
                $from = CommunityAnswerStatus::from($item['status']);
                foreach (CommunityQaAutomationService::path($from, CommunityAnswerStatus::Archived, [CommunityAnswerStatus::class, 'validTransitions']) as [$a, $b]) {
                    $this->answers->transitionStatus($item['id'], $a, $b);
                }
                AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_ARCHIVED, [
                    'answer_id' => $item['id'],
                    'from'      => $item['status'],
                    'reason'    => 'legacy in-Reach draft cleared (community:legacy-drafts); the question is open for the community-qa routine',
                ]);
                $item['result'] = 'archived';
                $cleared++;
            } catch (\Throwable $e) {
                $item['result'] = 'failed: ' . mb_substr($e->getMessage(), 0, 160);
            }
        }
        unset($item);

        return ['cleared' => $cleared, 'dry_run' => false, 'items' => $items];
    }
}

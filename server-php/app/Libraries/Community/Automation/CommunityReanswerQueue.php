<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

use App\Libraries\AuditLogger;
use App\Libraries\Community\OfficialAnswerCorrectionService;
use App\Libraries\Community\OfficialAnswerRepository;
use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * reach_community_reanswers — live community answers that re-vetting found
 * wrong, waiting for (or going through) their replacement.
 *
 * A row is opened by CommunityAnswerRepairService (reach:content-revet
 * --community, the daily quality sweep) and offered to the reach-community-qa
 * routine at the top of GET v1/automation/community/pending as a
 * `reanswer: true` item. The routine answers it through the ordinary
 * pending/{question}/answer endpoint; CommunityQaAutomationService turns that
 * text into a correction version of the SAME answer (origin 'revet', no quota)
 * and runs the usual vet → SystemApprover approval → publish → verify chain,
 * so the page is republished at the same URL.
 *
 * The hooks below are called by that chain when a revet submission ends:
 *   published      → row live, the correction is marked corrected and the
 *                    answer is no longer withdrawn;
 *   not published  → the answer row is put back exactly as it was (a kept-live
 *                    answer is live again with its previous approved version; a
 *                    withdrawn one stays down) and the question is offered
 *                    again, up to MAX_ATTEMPTS routine answers, then cancelled.
 */
class CommunityReanswerQueue
{
    public const TABLE        = 'reach_community_reanswers';
    public const OPEN         = ['queued', 'submitted'];
    public const MAX_ATTEMPTS = 2;
    public const CORRECTION_NOTE = 'Updated for accuracy.';

    /**
     * Answer-row fields a failed correction puts back. ai_assisted and
     * human_reviewed describe the approved version (the correction sets
     * ai_assisted, its system approval clears human_reviewed), so they go back
     * with it. Snapshots taken before these were added simply lack them.
     */
    private const SNAPSHOT_FIELDS = [
        'status', 'publication_status', 'approved_version', 'approved_version_checksum', 'current_version',
        'correction_state', 'correction_note', 'withdrawal_state', 'ai_assisted', 'human_reviewed',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly OfficialAnswerRepository $answers = new OfficialAnswerRepository())
    {
        $this->db = $db ?? Database::connect();
    }

    public function available(): bool
    {
        try {
            return SchemaGuard::hasTable($this->db, self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Open a re-answer request (no-op when the answer already has one open).
     *
     * @param list<string> $reasons
     * @return int the request id
     */
    public function enqueue(int $answerId, int $questionId, string $mode, string $source, array $reasons, ?int $qualityCheckId = null, string $status = 'queued'): int
    {
        $open = $this->openForAnswer($answerId);
        if ($open !== null) {
            return (int) $open['id'];
        }
        $now = gmdate('Y-m-d H:i:sP');
        $this->db->table(self::TABLE)->insert([
            'question_id'      => $questionId,
            'answer_id'        => $answerId,
            'mode'             => $mode,
            'source'           => $source,
            'reasons'          => self::json(array_slice($reasons, 0, 12)),
            'status'           => $status,
            'quality_check_id' => $qualityCheckId,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $id = (int) $this->db->insertID();
        AuditLogger::record('community.answer.reanswer_queued', [
            'reanswer_id' => $id, 'answer_id' => $answerId, 'question_id' => $questionId, 'mode' => $mode, 'status' => $status,
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->table(self::TABLE)->where('id', $id)->get()->getRowArray() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function openForAnswer(int $answerId): ?array
    {
        if (! $this->available()) {
            return null;
        }

        return $this->db->table(self::TABLE)->where('answer_id', $answerId)->whereIn('status', self::OPEN)
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    /** The question's request waiting for the routine, if any. @return array<string, mixed>|null */
    public function queuedForQuestion(int $questionId): ?array
    {
        if (! $this->available()) {
            return null;
        }

        return $this->db->table(self::TABLE)->where('question_id', $questionId)->where('status', 'queued')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray() ?: null;
    }

    public function setQualityCheck(int $id, ?int $checkId): void
    {
        if ($checkId !== null) {
            $this->db->table(self::TABLE)->where('id', $id)->update(['quality_check_id' => $checkId]);
        }
    }

    /** The answer row as it is now — what a failed correction puts back. @return array<string, mixed> */
    public function snapshot(int $answerId): array
    {
        $answer = $this->db->table('reach_community_official_answers')->where('id', $answerId)->get()->getRowArray() ?? [];

        return array_intersect_key($answer, array_flip(self::SNAPSHOT_FIELDS));
    }

    /**
     * A correction for this request is going through the pipeline now.
     *
     * @param array<string, mixed> $snapshot
     */
    public function markSubmitted(int $id, int $submissionId, array $snapshot, bool $routineAttempt): void
    {
        $b = $this->db->table(self::TABLE)->where('id', $id)
            ->set('status', 'submitted')
            ->set('submission_id', $submissionId)
            ->set('replaced_state', self::json($snapshot))
            ->set('last_error', null)
            ->set('updated_at', gmdate('Y-m-d H:i:sP'));
        if ($routineAttempt) {
            $b->set('attempts', 'attempts + 1', false);
        }
        $b->update();
    }

    // -------------------------------------------------------------------------
    // Hooks from CommunityQaAutomationService (revet submissions only)
    // -------------------------------------------------------------------------

    /** The correction is live at the same URL. */
    public function onPublished(array $sub): void
    {
        $row = $this->rowFor($sub);
        if ($row === null) {
            return;
        }
        $answerId = (int) $row['answer_id'];
        try {
            (new OfficialAnswerCorrectionService($this->answers))->markCorrected($answerId);
            $this->answers->save(['id' => $answerId, 'withdrawal_state' => 'none']);
        } catch (Throwable $e) {
            log_message('warning', "[CommunityReanswer] could not mark answer {$answerId} corrected: " . $e->getMessage());
        }
        $now = gmdate('Y-m-d H:i:sP');
        $this->db->table(self::TABLE)->where('id', (int) $row['id'])->update([
            'status' => 'live', 'last_error' => null, 'completed_at' => $now, 'updated_at' => $now,
        ]);
        AuditLogger::record('community.answer.reanswer_live', [
            'reanswer_id' => (int) $row['id'], 'answer_id' => $answerId, 'submission_id' => (int) $sub['id'], 'public_url' => $sub['public_url'] ?? null,
        ]);
    }

    /**
     * The correction was not published (vetting rejected it, a second revise,
     * a failed gate, prohibited, or publication gave up). Put the answer back
     * as it was and offer the question again — or give up after MAX_ATTEMPTS
     * routine answers, or at once for a prohibited topic.
     *
     * @param list<string> $reasons
     */
    public function onNotPublished(array $sub, string $outcome, array $reasons): void
    {
        $row = $this->rowFor($sub);
        if ($row === null) {
            return;
        }
        $answerId = (int) $row['answer_id'];
        $answer   = $this->answers->findById($answerId);

        // Published in Reach but not confirmed live within the window: the new
        // text did go out, so do not roll the record back under it.
        $publishedAnyway = ($answer['status'] ?? null) === 'published'
            && (int) ($answer['approved_version'] ?? 0) > (int) (self::decode($row['replaced_state'])['approved_version'] ?? 0);

        if (! $publishedAnyway) {
            $this->restore($answerId, self::decode($row['replaced_state']));
        }

        $giveUp = $publishedAnyway || $outcome === 'blocked' || (int) $row['attempts'] >= self::MAX_ATTEMPTS;
        $merged = array_values(array_unique([...array_map('strval', self::decode($row['reasons'])), ...array_slice(array_map('strval', $reasons), 0, 6)]));
        $now    = gmdate('Y-m-d H:i:sP');
        $this->db->table(self::TABLE)->where('id', (int) $row['id'])->update([
            'status'        => $giveUp ? 'cancelled' : 'queued',
            'reasons'       => self::json(array_slice($merged, -12)),
            'submission_id' => null,
            'last_error'    => mb_substr(($publishedAnyway ? 'Published in Reach but not confirmed live: ' : "Replacement {$outcome}: ") . implode(' ', array_slice($reasons, 0, 2)), 0, 1000),
            'completed_at'  => $giveUp ? $now : null,
            'updated_at'    => $now,
        ]);
        AuditLogger::record($giveUp ? 'community.answer.reanswer_cancelled' : 'community.answer.reanswer_queued', [
            'reanswer_id' => (int) $row['id'], 'answer_id' => $answerId, 'outcome' => $outcome,
            'mode' => $row['mode'], 'reasons' => array_slice($reasons, 0, 3),
        ]);
    }

    /**
     * Put an answer back as it was before the correction this request made
     * (its replaced_state). For community:revet-repair; the pipeline itself
     * does this in onNotPublished().
     *
     * @param array<string, mixed> $row a reach_community_reanswers row
     */
    public function restoreReplacedState(array $row): bool
    {
        $snapshot = self::decode($row['replaced_state'] ?? null);
        if ($snapshot === []) {
            return false;
        }
        $this->restore((int) $row['answer_id'], $snapshot);

        return true;
    }

    /** @param array<string, mixed> $snapshot */
    private function restore(int $answerId, array $snapshot): void
    {
        if ($snapshot === []) {
            return;
        }
        try {
            $this->db->table('reach_community_official_answers')->where('id', $answerId)
                ->update(array_intersect_key($snapshot, array_flip(self::SNAPSHOT_FIELDS)) + ['updated_at' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            log_message('error', "[CommunityReanswer] could not restore answer {$answerId}: " . $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    private function rowFor(array $sub): ?array
    {
        if (($sub['origin'] ?? 'routine') !== 'revet' || empty($sub['reanswer_id'])) {
            return null;
        }
        $row = $this->find((int) $sub['reanswer_id']);

        return $row !== null && $row['status'] === 'submitted' ? $row : null;
    }

    // -------------------------------------------------------------------------
    // The routine's view
    // -------------------------------------------------------------------------

    /**
     * Requests waiting for the routine, oldest first. Requests whose question
     * was closed, merged or archived, or whose answer was archived, since they
     * were opened are cancelled here.
     *
     * @return list<array<string, mixed>>
     */
    public function forRoutine(int $limit = 10): array
    {
        if (! $this->available()) {
            return [];
        }
        $this->settleOrphans();
        $rows = $this->db->query(
            "SELECT r.*, q.uuid AS question_uuid, q.title, q.body, q.category, q.product, q.source_type, q.public_url AS question_url,
                    q.status AS question_status, q.handled_by, q.moderation_state, q.personal_data_detected,
                    a.uuid AS answer_uuid, a.status AS answer_status, a.public_url AS answer_url,
                    EXISTS (SELECT 1 FROM reach_community_automation_submissions s
                             WHERE s.question_id = r.question_id AND s.status IN ('pending', 'approved')) AS in_flight
               FROM reach_community_reanswers r
               JOIN reach_community_questions q ON q.id = r.question_id
               JOIN reach_community_official_answers a ON a.id = r.answer_id
              WHERE r.status = 'queued'
              ORDER BY r.id ASC
              LIMIT ?",
            [max(1, min(100, $limit)) * 2]
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $why = $this->unanswerable($row);
            if ($why !== null) {
                $this->cancel((int) $row['id'], $why);
                continue;
            }
            if (\App\Libraries\Support\PgBoolean::isTrue($row['in_flight'])) {
                continue;
            }
            $live  = $row['mode'] === 'kept_live';
            $out[] = [
                'question_id'       => (string) $row['question_uuid'],
                'title'             => (string) $row['title'],
                'body'              => (string) $row['body'],
                'category_slug'     => $row['category'],
                'product_slug'      => $row['product'],
                'asked_by'          => $row['source_type'] === 'official_question' ? 'curated' : 'member',
                'thread_url'        => $row['source_type'] === CommunityMemberQuestionFeedService::SOURCE_TYPE ? $row['question_url'] : null,
                'reanswer'          => true,
                'reanswer_id'       => (int) $row['id'],
                'counts_against_quota' => false,
                'replaces'          => [
                    'answer_id'  => (string) $row['answer_uuid'],
                    'state'      => $live ? 'live' : 'withdrawn',
                    'public_url' => $row['answer_url'] ?? $row['question_url'],
                ],
                'why'               => array_values(array_map('strval', self::decode($row['reasons']))),
                'previous_attempts' => (int) $row['attempts'],
                'answer_endpoint'   => 'POST /v1/automation/community/pending/' . $row['question_uuid'] . '/answer',
                'rules'             => [
                    $live
                        ? 'The current answer is live but needs changes. Write a complete, corrected answer that fixes every point in "why"; it replaces the current text when it passes vetting.'
                        : 'The previous answer was taken down because it was wrong. Write a completely new, correct answer that fixes every point in "why" — do not reuse the old text.',
                    'Answer the same question in its own terms; the question and its URL do not change.',
                    'POST it to answer_endpoint like any inbox answer. It does not count against today\'s quota, even when max_this_run is 0.',
                ],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * A request still marked submitted whose submission ended without going
     * through the pipeline's own finish (e.g. the inbox withdrew it when the
     * member deleted the question): settle it as not published.
     */
    private function settleOrphans(): void
    {
        try {
            $rows = $this->db->query(
                "SELECT s.* FROM reach_community_reanswers r
                   JOIN reach_community_automation_submissions s ON s.id = r.submission_id
                  WHERE r.status = 'submitted' AND s.status IN ('withdrawn', 'failed', 'blocked')
                  LIMIT 20"
            )->getResultArray();
            foreach ($rows as $sub) {
                $this->onNotPublished($sub, (string) $sub['status'], (array) (json_decode((string) $sub['reasons_json'], true) ?: []));
            }
        } catch (Throwable $e) {
            log_message('warning', '[CommunityReanswer] could not settle orphaned re-answers: ' . $e->getMessage());
        }
    }

    public function countQueued(): int
    {
        if (! $this->available()) {
            return 0;
        }

        return (int) $this->db->table(self::TABLE)->where('status', 'queued')->countAllResults();
    }

    /** @return array<string, int> open rows by status, for reports */
    public function counts(): array
    {
        $out = ['queued' => 0, 'submitted' => 0];
        if (! $this->available()) {
            return $out;
        }
        foreach ($this->db->table(self::TABLE)->select('status, COUNT(*) AS n')->whereIn('status', self::OPEN)
            ->groupBy('status')->get()->getResultArray() as $r) {
            $out[$r['status']] = (int) $r['n'];
        }

        return $out;
    }

    private function unanswerable(array $row): ?string
    {
        if (in_array($row['question_status'], ['archived', 'duplicate_merged'], true)) {
            return 'The question was ' . $row['question_status'] . '.';
        }
        if (in_array($row['handled_by'], [CommunityInboxPolicy::CLOSED, CommunityInboxPolicy::CLOSED_BY_PERSON], true)) {
            return 'The question was closed.';
        }
        if (($row['moderation_state'] ?? 'clean') !== 'clean' || \App\Libraries\Support\PgBoolean::isTrue($row['personal_data_detected'] ?? false)) {
            return 'The question is held for moderation or contains personal data.';
        }
        if ($row['answer_status'] === 'archived') {
            return 'The answer it replaces was archived.';
        }

        return null;
    }

    private function cancel(int $id, string $why): void
    {
        $now = gmdate('Y-m-d H:i:sP');
        $this->db->table(self::TABLE)->where('id', $id)->update([
            'status' => 'cancelled', 'last_error' => $why, 'completed_at' => $now, 'updated_at' => $now,
        ]);
        AuditLogger::record('community.answer.reanswer_cancelled', ['reanswer_id' => $id, 'reason' => $why]);
    }

    /** @return array<mixed> */
    private static function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        return (array) (json_decode((string) ($json ?? ''), true) ?: []);
    }

    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

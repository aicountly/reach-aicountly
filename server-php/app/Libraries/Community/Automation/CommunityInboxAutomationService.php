<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityQuestionStatus;
use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\AuditLogger;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\CommunityQuestionRepository;
use App\Libraries\Community\OfficialAnswerRepository;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Support\PgBoolean;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Keeps the Community Question Inbox moving with nobody in the loop.
 *
 * Reach does not write community answers; the reach-community-qa Claude
 * Routine does. This service decides, for every open question, who handles it
 * (CommunityInboxPolicy) and makes it so:
 *
 *   - answered (its answer is published) → the question is marked published;
 *   - already answered elsewhere (same normalised title, the same duplicate
 *     cluster, or a strong related link to a published question) → closed as
 *     a duplicate and linked to that question;
 *   - personal data, moderation flag, spam, a prohibited topic, a vetting-model
 *     reject (community_question rubric), or two answers that were not
 *     published → closed by the bot with the reason;
 *   - an old in-Reach draft that has not moved for automation.community.stuck_hours
 *     → the draft is archived and the question handed to the routine queue;
 *   - otherwise → queued for the routine (GET v1/automation/community/pending).
 *
 * Every step is idempotent: a sweep over a settled inbox writes nothing. Run
 * by community:schedule (every 5 minutes) and before each pending read.
 */
class CommunityInboxAutomationService
{
    private const SCAN_LIMIT       = 500;
    private const TRIAGE_PER_SWEEP = 3;
    private const FINAL_HANDLERS   = [CommunityInboxPolicy::PUBLISHED, CommunityInboxPolicy::CLOSED, CommunityInboxPolicy::CLOSED_BY_PERSON];

    private BaseConnection $db;
    private ?ContentVettingService $vetting;

    public function __construct(
        ?BaseConnection $db = null,
        ?ContentVettingService $vetting = null,
        private readonly OfficialAnswerRepository $answers = new OfficialAnswerRepository(),
        private readonly CommunityQuestionRepository $questions = new CommunityQuestionRepository(),
    ) {
        $this->db      = $db ?? Database::connect();
        $this->vetting = $vetting;
    }

    // -------------------------------------------------------------------------
    // Sweep
    // -------------------------------------------------------------------------

    /**
     * Settle every open question. With $triage, also has the vetting model
     * screen a few queued member questions (it is slow, so a GET skips it).
     *
     * @return array<string, int>
     */
    public function sweep(bool $triage = true, int $limit = self::SCAN_LIMIT): array
    {
        $summary = ['scanned' => 0, 'changed' => 0, 'published' => 0, 'closed' => 0, 'released' => 0,
            'queued' => 0, 'screened' => 0, 'triaged' => 0, 'triage_closed' => 0, 'triage_unavailable' => 0, 'errors' => 0];

        foreach ($this->openRows($limit) as $row) {
            $summary['scanned']++;
            try {
                $result = $this->apply($row);
                foreach ($result as $key) {
                    $summary[$key] = ($summary[$key] ?? 0) + 1;
                }
            } catch (Throwable $e) {
                $summary['errors']++;
                log_message('error', '[CommunityInbox] question ' . $row['id'] . ': ' . $e->getMessage());
            }
        }

        if ($triage && (bool) ReachConfig::get('automation.community.triage', true)) {
            foreach ($this->triage() as $key => $n) {
                $summary[$key] += $n;
            }
        }

        return $summary;
    }

    /** Settle one question now (after a submission changes, or before answering it). */
    public function refreshQuestionId(int $questionId): ?array
    {
        $rows = $this->openRows(1, $questionId);
        if ($rows !== []) {
            $this->apply($rows[0]);
        }
        return $this->questions->findById($questionId);
    }

    public function refreshByUuid(string $uuid): ?array
    {
        $question = $this->questions->findByUuid($uuid);
        return $question === null ? null : $this->refreshQuestionId((int) $question['id']);
    }

    // -------------------------------------------------------------------------
    // The routine's queue
    // -------------------------------------------------------------------------

    /**
     * Questions waiting for the routine's answer: members' first, then the
     * highest triage score, then the oldest. One per normalised title, so the
     * routine never answers the same question twice in a run.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 20, bool $settleFirst = true): array
    {
        if ($settleFirst) {
            $this->sweep(false);
        }
        $limit = max(1, min(100, $limit));

        $rows = $this->db->query(
            "SELECT q.id, q.uuid, q.title, q.body, q.category, q.product, q.source_type, q.intake_timestamp,
                    q.routine_queued_at, q.triage_score, q.triage_decision, q.public_url,
                    (SELECT c.risk_classification FROM reach_community_question_classifications c
                      WHERE c.question_id = q.id ORDER BY c.id DESC LIMIT 1) AS risk,
                    (SELECT COUNT(*) FROM reach_community_automation_submissions s
                      WHERE s.question_id = q.id AND s.status IN ('withdrawn', 'failed')) AS previous_attempts
               FROM reach_community_questions q
              WHERE q.handled_by = 'routine_queue'
                AND q.routine_queued_at IS NOT NULL
                AND q.status NOT IN ('archived', 'duplicate_merged', 'published', 'withdrawn')
                AND q.moderation_state = 'clean'
                AND q.personal_data_detected = FALSE
                AND EXISTS (SELECT 1 FROM reach_community_question_classifications c WHERE c.question_id = q.id)
                AND NOT EXISTS (
                        SELECT 1 FROM reach_community_official_answers a
                         WHERE a.question_id = q.id
                           AND a.status <> 'archived'
                           AND (a.status NOT IN ('draft_requested', 'validation_failed')
                                OR EXISTS (SELECT 1 FROM reach_community_answer_versions v WHERE v.answer_id = a.id))
                    )
                AND NOT EXISTS (
                        SELECT 1 FROM reach_community_automation_submissions s
                         WHERE s.question_id = q.id
                           AND (s.status IN ('pending', 'approved', 'published', 'blocked')
                                OR s.created_at > NOW() - INTERVAL '1 day')
                    )
                AND (SELECT COUNT(*) FROM reach_community_automation_submissions s
                      WHERE s.question_id = q.id AND s.status IN ('withdrawn', 'failed')) < ?
              ORDER BY (q.source_type = 'official_question') ASC, q.triage_score DESC, q.intake_timestamp ASC, q.id ASC
              LIMIT ?",
            [CommunityInboxPolicy::MAX_ROUTINE_ATTEMPTS, $limit * 3]
        )->getResultArray();

        $out  = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = CommunityQaAutomationService::normaliseTitle((string) $row['title']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = [
                'question_id'       => (string) $row['uuid'],
                'title'             => (string) $row['title'],
                'body'              => (string) $row['body'],
                'category_slug'     => $row['category'],
                'product_slug'      => $row['product'],
                'asked_by'          => $row['source_type'] === 'official_question' ? 'curated' : 'member',
                'asked_at'          => $row['intake_timestamp'],
                // A member's question from aicountly.com: the answer is posted on this thread.
                'thread_url'        => $row['source_type'] === CommunityMemberQuestionFeedService::SOURCE_TYPE ? $row['public_url'] : null,
                'queued_at'         => $row['routine_queued_at'],
                'triage_score'      => (float) $row['triage_score'],
                'risk'              => $row['risk'],
                'screened'          => $row['triage_decision'] !== null,
                'previous_attempts' => (int) $row['previous_attempts'],
                'answer_endpoint'   => 'POST /v1/automation/community/pending/' . $row['uuid'] . '/answer',
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** How many questions are in each handler state (Question Inbox summary). */
    public function counts(): array
    {
        $counts = array_fill_keys([...CommunityInboxPolicy::HANDLERS, 'unassigned'], 0);
        foreach ($this->db->query(
            "SELECT COALESCE(handled_by, 'unassigned') AS h, COUNT(*) AS n FROM reach_community_questions GROUP BY 1"
        )->getResultArray() as $row) {
            $counts[$row['h']] = (int) $row['n'];
        }
        return $counts;
    }

    /**
     * The routine will not answer a queued question (it cannot answer it
     * truthfully, e.g. the product code does not show how): the bot closes it
     * with the routine's reason rather than leaving it open.
     *
     * @throws CommunityQaRefusal 404 unknown, 409 not in the routine queue
     */
    public function decline(string $uuid, string $reason): array
    {
        $question = $this->refreshByUuid($uuid);
        if ($question === null) {
            throw new CommunityQaRefusal('Question not found: ' . $uuid, 404);
        }
        if (($question['handled_by'] ?? null) !== CommunityInboxPolicy::QUEUE) {
            throw new CommunityQaRefusal('This question is not waiting for the routine (' . ($question['handled_by'] ?? 'unassigned') . ': ' . ($question['handling_note'] ?? '') . ')', 409);
        }

        $rows = $this->openRows(1, (int) $question['id']);
        $this->close($rows[0], [
            'handled_by'   => CommunityInboxPolicy::CLOSED,
            'close_reason' => 'declined',
            'note'         => 'Closed: the community-qa routine could not answer it — ' . $reason,
        ]);

        return $this->viewOf($this->questions->findById((int) $question['id']) ?? $question);
    }

    /**
     * Close a question because of what happened to it at its source (the
     * member deleted it, moderation hid it, it was answered on the site). An
     * answer still waiting for vetting is withdrawn with the same reason; one
     * already published stays, and the question keeps its published state.
     *
     * @return bool whether anything changed
     */
    public function closeForSource(int $questionId, string $reason, string $note): bool
    {
        $rows = $this->openRows(1, $questionId);
        $row  = $rows[0] ?? null;
        if ($row === null || $row['published_answer_id'] !== null
            || in_array($row['handled_by'], [CommunityInboxPolicy::CLOSED, CommunityInboxPolicy::CLOSED_BY_PERSON], true)
        ) {
            return false;
        }

        if ($row['inflight_status'] === 'pending') {
            $this->db->table('reach_community_automation_submissions')
                ->where('question_id', $questionId)->where('status', 'pending')
                ->update([
                    'status'        => 'withdrawn',
                    'reasons_json'  => json_encode([$note], JSON_UNESCAPED_UNICODE),
                    'next_retry_at' => null,
                    'updated_at'    => gmdate('Y-m-d H:i:sP'),
                ]);
            $row['inflight_status'] = null;
        }

        $this->close($row, ['handled_by' => CommunityInboxPolicy::CLOSED, 'close_reason' => $reason, 'note' => $note]);
        return true;
    }

    /** @return array<string, mixed> */
    public function viewOf(array $question): array
    {
        return [
            'question_id'   => (string) $question['uuid'],
            'status'        => (string) $question['status'],
            'handled_by'    => $question['handled_by'] ?? null,
            'note'          => $question['handling_note'] ?? null,
            'close_reason'  => $question['close_reason'] ?? null,
            'queued_at'     => $question['routine_queued_at'] ?? null,
            'closed_at'     => $question['closed_at'] ?? null,
            'updated_at'    => $question['handling_updated_at'] ?? null,
        ];
    }

    /**
     * Mirror a just-published answer onto its question. Called by the
     * publisher; never lets a bookkeeping failure undo a publication.
     */
    public static function markPublishedForAnswer(int $answerId): void
    {
        try {
            $db  = Database::connect();
            $row = $db->table('reach_community_official_answers')->select('question_id, public_url')->where('id', $answerId)->get()->getRowArray();
            if ($row !== null && $row['question_id'] !== null) {
                (new self($db))->markPublished((int) $row['question_id'], $row['public_url'] ?? null);
            }
        } catch (Throwable $e) {
            log_message('warning', '[CommunityInbox] could not mark question published for answer ' . $answerId . ': ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Facts → decision → effect
    // -------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function openRows(int $limit, ?int $questionId = null): array
    {
        $final = "'" . implode("','", self::FINAL_HANDLERS) . "'";
        $where = $questionId !== null
            ? 'q.id = ' . (int) $questionId
            : "(q.handled_by IS NULL OR q.handled_by NOT IN ({$final}))";

        return $this->db->query(
            "SELECT q.id, q.uuid, q.title, q.status, q.source_type, q.moderation_state, q.personal_data_detected,
                    q.spam_score, q.duplicate_cluster_id, q.handled_by, q.handling_note, q.routine_queued_at,
                    q.triage_decision, q.automation_failures,
                    EXISTS (SELECT 1 FROM reach_community_question_classifications c WHERE c.question_id = q.id) AS classified,
                    pa.id AS published_answer_id, pa.public_url AS published_url,
                    la.id AS live_answer_id, la.status AS live_answer_status,
                    EXTRACT(EPOCH FROM (NOW() - la.updated_at))::bigint AS live_answer_age,
                    (SELECT COUNT(*) FROM reach_community_answer_versions v WHERE v.answer_id = la.id) AS live_answer_versions,
                    fl.status AS inflight_status,
                    (SELECT COUNT(*) FROM reach_community_automation_submissions s
                      WHERE s.question_id = q.id AND s.status IN ('withdrawn', 'failed')) AS failed_submissions,
                    (SELECT COUNT(*) FROM reach_community_automation_submissions s
                      WHERE s.question_id = q.id AND s.status = 'blocked') AS blocked_submissions,
                    (SELECT s.reasons_json::text FROM reach_community_automation_submissions s
                      WHERE s.question_id = q.id AND s.status IN ('withdrawn', 'failed', 'blocked')
                      ORDER BY s.id DESC LIMIT 1) AS last_reasons,
                    (SELECT EXTRACT(EPOCH FROM (NOW() - MAX(s.updated_at)))::bigint FROM reach_community_automation_submissions s
                      WHERE s.question_id = q.id AND s.status IN ('withdrawn', 'failed')) AS seconds_since_last_failure
               FROM reach_community_questions q
               LEFT JOIN LATERAL (
                    SELECT a.id, a.public_url FROM reach_community_official_answers a
                     WHERE a.question_id = q.id AND a.status = 'published' ORDER BY a.id DESC LIMIT 1
               ) pa ON TRUE
               LEFT JOIN LATERAL (
                    SELECT a.id, a.status, a.updated_at FROM reach_community_official_answers a
                     WHERE a.question_id = q.id AND a.status <> 'archived' ORDER BY a.id DESC LIMIT 1
               ) la ON TRUE
               LEFT JOIN LATERAL (
                    SELECT s.status FROM reach_community_automation_submissions s
                     WHERE s.question_id = q.id AND s.status IN ('pending', 'approved') ORDER BY s.id DESC LIMIT 1
               ) fl ON TRUE
              WHERE {$where}
              ORDER BY q.id ASC
              LIMIT " . max(1, $limit)
        )->getResultArray();
    }

    /** @return array<string, mixed> */
    private function facts(array $row): array
    {
        $open = $row['published_answer_id'] === null
            && ! in_array($row['status'], ['archived', 'duplicate_merged'], true)
            && $row['inflight_status'] === null;

        return [
            'status'                     => (string) $row['status'],
            'source_type'                => (string) $row['source_type'],
            'moderation_state'           => (string) $row['moderation_state'],
            'personal_data_detected'     => PgBoolean::isTrue($row['personal_data_detected']),
            'spam_score'                 => (float) $row['spam_score'],
            'classified'                 => PgBoolean::isTrue($row['classified']),
            'automation_failures'        => (int) $row['automation_failures'],
            'triage_decision'            => $row['triage_decision'],
            'triage_reasons'             => [],
            'published_answer'           => $row['published_answer_id'] !== null ? ['url' => $row['published_url']] : null,
            'live_answer'                => $row['live_answer_id'] !== null ? [
                'status'      => (string) $row['live_answer_status'],
                'versions'    => (int) $row['live_answer_versions'],
                'age_seconds' => (int) $row['live_answer_age'],
            ] : null,
            'inflight'                   => $row['inflight_status'] !== null ? ['status' => (string) $row['inflight_status']] : null,
            'failed_submissions'         => (int) $row['failed_submissions'],
            'blocked_submissions'        => (int) $row['blocked_submissions'],
            'last_reasons'               => array_values(array_filter(array_map('strval', (array) (json_decode((string) ($row['last_reasons'] ?? '[]'), true) ?: [])))),
            'seconds_since_last_failure' => $row['seconds_since_last_failure'] !== null ? (int) $row['seconds_since_last_failure'] : null,
            'duplicate_of'               => $open ? $this->answeredDuplicateOf($row) : null,
        ];
    }

    /**
     * Apply the policy to one row.
     *
     * @return list<string> summary keys that changed
     */
    private function apply(array $row): array
    {
        $changed = [];

        // A closed question keeps its close (and its reason) for good.
        if (in_array($row['handled_by'] ?? null, [CommunityInboxPolicy::CLOSED, CommunityInboxPolicy::CLOSED_BY_PERSON], true)
            && in_array($row['status'], ['archived', 'duplicate_merged'], true)
        ) {
            return $changed;
        }

        // A question that never got its personal-data screen gets it here,
        // a bounded number of times.
        if (! PgBoolean::isTrue($row['classified']) && (int) $row['automation_failures'] < CommunityInboxPolicy::MAX_AUTOMATION_FAILURES
            && ! in_array($row['status'], ['archived', 'duplicate_merged'], true)
        ) {
            try {
                (new CommunityQuestionIntakeService())->completeProcessing((int) $row['id']);
                $changed[] = 'screened';
            } catch (Throwable $e) {
                $this->db->table('reach_community_questions')->where('id', (int) $row['id'])
                    ->set('automation_failures', 'automation_failures + 1', false)->update();
                log_message('warning', '[CommunityInbox] screen failed for question ' . $row['id'] . ': ' . $e->getMessage());
            }
            $row = $this->openRows(1, (int) $row['id'])[0] ?? $row;
        }

        $stuckHours = max(1, (int) ReachConfig::get('automation.community.stuck_hours', 6));
        $decision   = CommunityInboxPolicy::decide($this->facts($row), $stuckHours);

        switch ($decision['action']) {
            case 'publish':
                if ($this->markPublished((int) $row['id'], $row['published_url'] ?? null)) {
                    $changed[] = 'published';
                }
                break;
            case 'close':
                $this->close($row, $decision);
                $changed[] = 'closed';
                break;
            case 'release':
                $this->archiveAnswer((int) $row['live_answer_id'], 'stuck legacy in-Reach draft handed to the community-qa routine after ' . $stuckHours . ' h');
                $this->setHandling($row, $decision, true);
                $changed[] = 'released';
                break;
            case 'queue':
                // Keep a note that says how the question got into the queue
                // (an old draft released after N h, a member's edit) rather
                // than overwrite it with the plain "queued" line.
                if ($decision['note'] === CommunityInboxPolicy::QUEUED_NOTE && $row['routine_queued_at'] !== null
                    && ($row['handled_by'] ?? null) === CommunityInboxPolicy::QUEUE
                    && (str_contains((string) $row['handling_note'], 'archived after')
                        || str_contains((string) $row['handling_note'], 'Edited by the member'))
                ) {
                    break;
                }
                if ($this->setHandling($row, $decision, true)) {
                    $changed[] = 'queued';
                }
                break;
            case 'label_closed':
                $this->setHandling($row, $decision, false, true);
                break;
            default: // wait, vetting, publishing
                $this->setHandling($row, $decision, false);
        }

        if ($changed !== [] || ($row['handled_by'] ?? null) !== $decision['handled_by']) {
            $changed[] = 'changed';
        }
        return $changed;
    }

    /**
     * A published answer to the same question, if there is one: the same
     * normalised title, the same duplicate cluster, or a strong related link.
     *
     * @return array{id: int, title: string, url: ?string}|null
     */
    private function answeredDuplicateOf(array $row): ?array
    {
        $found = $this->db->query(
            "SELECT p.id, p.title, a.public_url
               FROM reach_community_questions p
               JOIN reach_community_official_answers a ON a.question_id = p.id AND a.status = 'published'
              WHERE p.id <> :id:
                AND (
                        btrim(regexp_replace(lower(p.title), '[^a-z0-9]+', ' ', 'g')) = :norm:
                     OR (p.duplicate_cluster_id IS NOT NULL AND p.duplicate_cluster_id = :cluster:)
                     OR EXISTS (
                            SELECT 1 FROM reach_community_question_related_links l
                             WHERE l.similarity >= 0.9
                               AND ((l.question_id = :id: AND l.related_question_id = p.id)
                                 OR (l.related_question_id = :id: AND l.question_id = p.id))
                        )
                    )
              ORDER BY a.id ASC
              LIMIT 1",
            [
                'id'      => (int) $row['id'],
                'norm'    => CommunityQaAutomationService::normaliseTitle((string) $row['title']),
                'cluster' => $row['duplicate_cluster_id'] !== null ? (int) $row['duplicate_cluster_id'] : null,
            ]
        )->getRowArray();

        return $found === null ? null : ['id' => (int) $found['id'], 'title' => (string) $found['title'], 'url' => $found['public_url']];
    }

    /** Question status follows its live answer; handled_by says so. */
    private function markPublished(int $questionId, ?string $url): bool
    {
        $note = 'Answered and published on aicountly.com/community' . ($url ? ': ' . $url : '.');
        $this->db->query(
            "UPDATE reach_community_questions
                SET status = CASE WHEN status IN ('archived', 'duplicate_merged') THEN status ELSE 'published' END,
                    handled_by = 'published', handling_note = ?, handling_updated_at = NOW(),
                    closed_at = NULL, close_reason = NULL, updated_at = NOW()
              WHERE id = ?
                AND (handled_by IS DISTINCT FROM 'published' OR handling_note IS DISTINCT FROM ?
                     OR status NOT IN ('published', 'archived', 'duplicate_merged'))",
            [$note, $questionId, $note]
        );
        $changed = $this->db->affectedRows() > 0;
        if ($changed) {
            AuditLogger::record(AuditLogger::COMMUNITY_INBOX_PUBLISHED, ['question_id' => $questionId, 'public_url' => $url]);
        }
        return $changed;
    }

    /** Write handled_by/note only when they change, so a settled sweep writes nothing. */
    private function setHandling(array $row, array $decision, bool $offered, bool $closedByOthers = false): bool
    {
        $offerNow = $offered && $row['routine_queued_at'] === null;
        if (($row['handled_by'] ?? null) === $decision['handled_by'] && ($row['handling_note'] ?? null) === $decision['note'] && ! $offerNow) {
            return false;
        }

        $builder = $this->db->table('reach_community_questions')->where('id', (int) $row['id'])
            ->set('handled_by', $decision['handled_by'])
            ->set('handling_note', mb_substr($decision['note'], 0, 2000))
            ->set('handling_updated_at', 'NOW()', false);
        if ($offerNow) {
            $builder->set('routine_queued_at', 'NOW()', false);
        }
        if ($closedByOthers) {
            $builder->set('closed_at', 'COALESCE(closed_at, updated_at)', false);
        }
        $builder->update();

        if ($offerNow) {
            AuditLogger::record(AuditLogger::COMMUNITY_INBOX_QUEUED, ['question_id' => (int) $row['id'], 'note' => $decision['note']]);
        }
        return true;
    }

    /** Close by the bot: retire any unpublished answer, archive or merge the question, keep the reason. */
    private function close(array $row, array $decision): void
    {
        $questionId = (int) $row['id'];

        if ($row['live_answer_id'] !== null && $row['live_answer_status'] !== 'published' && $row['inflight_status'] === null) {
            $this->archiveAnswer((int) $row['live_answer_id'], 'question closed: ' . $decision['close_reason']);
        }

        $duplicate = $decision['close_reason'] === 'duplicate' ? $this->answeredDuplicateOf($row) : null;
        $target    = $duplicate !== null ? CommunityQuestionStatus::DuplicateMerged : CommunityQuestionStatus::Archived;
        $from      = CommunityQuestionStatus::tryFrom((string) $row['status']);
        if ($from !== null && ! $from->isTerminal()) {
            try {
                foreach (CommunityQaAutomationService::path($from, $target, [CommunityQuestionStatus::class, 'validTransitions']) as [$a, $b]) {
                    $this->questions->transitionStatus($questionId, $a, $b);
                }
            } catch (Throwable $e) {
                // A status with no legal route to the target (none should
                // exist): the close is still recorded, the status mirrors it.
                log_message('warning', "[CommunityInbox] question {$questionId}: " . $e->getMessage());
                $this->db->table('reach_community_questions')->where('id', $questionId)
                    ->update(['status' => $target->value, 'updated_at' => gmdate('Y-m-d H:i:sP')]);
            }
        }

        $this->db->table('reach_community_questions')->where('id', $questionId)
            ->set('handled_by', CommunityInboxPolicy::CLOSED)
            ->set('close_reason', $decision['close_reason'])
            ->set('handling_note', mb_substr($decision['note'], 0, 2000))
            ->set('answered_by_question_id', $duplicate['id'] ?? null)
            ->set('closed_at', 'NOW()', false)
            ->set('handling_updated_at', 'NOW()', false)
            ->update();

        AuditLogger::record(AuditLogger::COMMUNITY_INBOX_CLOSED, [
            'question_id'             => $questionId,
            'reason'                  => $decision['close_reason'],
            'note'                    => mb_substr($decision['note'], 0, 300),
            'answered_by_question_id' => $duplicate['id'] ?? null,
        ]);
    }

    private function archiveAnswer(int $answerId, string $why): void
    {
        $answer = $this->answers->findById($answerId);
        $from   = CommunityAnswerStatus::tryFrom((string) ($answer['status'] ?? ''));
        if ($from === null || $from === CommunityAnswerStatus::Archived || $from === CommunityAnswerStatus::Published) {
            return;
        }
        foreach (CommunityQaAutomationService::path($from, CommunityAnswerStatus::Archived, [CommunityAnswerStatus::class, 'validTransitions']) as [$a, $b]) {
            $this->answers->transitionStatus($answerId, $a, $b);
        }
        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_ARCHIVED, ['answer_id' => $answerId, 'from' => $from->value, 'reason' => $why]);
    }

    // -------------------------------------------------------------------------
    // Triage (vetting model, community_question rubric)
    // -------------------------------------------------------------------------

    /** @return array{triaged: int, triage_closed: int, triage_unavailable: int} */
    private function triage(): array
    {
        $out  = ['triaged' => 0, 'triage_closed' => 0, 'triage_unavailable' => 0];
        $rows = $this->db->table('reach_community_questions')
            ->select('id, title, body, category, product')
            ->where('handled_by', CommunityInboxPolicy::QUEUE)
            ->where('routine_queued_at IS NOT NULL', null, false)
            ->where('triage_decision IS NULL', null, false)
            ->where('source_type !=', 'official_question')
            ->where('automation_failures <', CommunityInboxPolicy::MAX_AUTOMATION_FAILURES)
            ->orderBy('id', 'ASC')
            ->limit(self::TRIAGE_PER_SWEEP)
            ->get()->getResultArray();

        $vetting = $this->vetting ?? CommunityQaAutomationService::vettingService();
        foreach ($rows as $q) {
            try {
                $result = $vetting->vet('community_question', [
                    'question_title' => (string) $q['title'],
                    'question_body'  => (string) $q['body'],
                ], [
                    'category' => $q['category'],
                    'product'  => $q['product'],
                    'task'     => 'Screen this member question before an AICOUNTLY desk answers it. There is no answer yet.',
                ], 'community_question:' . $q['id']);
            } catch (VettingUnavailableException $e) {
                $this->db->table('reach_community_questions')->where('id', (int) $q['id'])
                    ->set('automation_failures', 'automation_failures + 1', false)->update();
                $out['triage_unavailable']++;
                continue;
            } catch (Throwable $e) {
                $this->db->table('reach_community_questions')->where('id', (int) $q['id'])
                    ->set('automation_failures', 'automation_failures + 1', false)->update();
                log_message('error', '[CommunityInbox] triage failed for question ' . $q['id'] . ': ' . $e->getMessage());
                $out['triage_unavailable']++;
                continue;
            }

            $this->db->table('reach_community_questions')->where('id', (int) $q['id'])->update([
                'triage_decision'       => $result->decision,
                'triage_vetting_run_id' => $result->runId,
                'triage_checked_at'     => gmdate('Y-m-d H:i:sP'),
            ]);
            $out['triaged']++;

            if ($result->decision === VettingResult::REJECT) {
                $row = $this->openRows(1, (int) $q['id'])[0] ?? null;
                if ($row !== null && $row['inflight_status'] === null && $row['published_answer_id'] === null) {
                    $reasons = trim(implode(' ', array_slice($result->reasons, 0, 2)));
                    $this->close($row, [
                        'handled_by'   => CommunityInboxPolicy::CLOSED,
                        'close_reason' => 'rejected',
                        'note'         => 'Closed by the vetting model' . ($reasons !== '' ? ': ' . mb_substr($reasons, 0, 400) : '.'),
                    ]);
                    $out['triage_closed']++;
                }
            }
        }

        return $out;
    }
}

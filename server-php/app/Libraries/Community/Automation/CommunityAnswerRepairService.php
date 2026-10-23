<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\AuditLogger;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use App\Libraries\Community\OfficialAnswerPublishingService;
use App\Libraries\Community\OfficialAnswerRepository;
use App\Libraries\Community\OfficialAnswerWithdrawalService;
use App\Libraries\HtmlSanitizer;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * What re-vetting (reach:content-revet --community, the daily quality sweep)
 * does to a LIVE community answer the vetting model did not approve. No person
 * is in the loop; every step uses the existing community machinery.
 *
 *   reject                → taken down reversibly: unpublished on aicountly.com
 *                           (OfficialAnswerPublishingService::unpublish — the
 *                           receiver's restore endpoint brings it back) and
 *                           marked withdrawn in Reach (OfficialAnswerWithdrawalService,
 *                           versions and audit kept); the question is queued for
 *                           the reach-community-qa routine as a priority
 *                           re-answer (no quota). The new answer is a
 *                           correction version of the same answer, so it goes
 *                           back up at the same URL.
 *   revise, with text     → the vetting model's revised answer becomes a
 *                           correction version (OfficialAnswerCorrectionService,
 *                           note "Updated for accuracy.") and goes through the
 *                           automation chain: vet → SystemApprover approval
 *                           (compliance_review for tier 2/3) → publish → verify.
 *                           The current text stays live until it is replaced;
 *                           if the correction is not published the answer is
 *                           put back and the question is queued for a re-answer.
 *   revise, without text  → left live; queued for a re-answer the same way.
 *
 * A revise never takes an answer down. Risk tier 4 (prohibited) is never
 * published by the desks, so such an answer is not corrected or re-answered
 * (a reject still takes it down).
 */
class CommunityAnswerRepairService
{
    private BaseConnection $db;
    private CommunityReanswerQueue $queue;
    private ?CommunityQaAutomationService $automation;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ?ContentVettingService $vetting = null,
        ?CommunityQaAutomationService $automation = null,
        private readonly OfficialAnswerRepository $answers = new OfficialAnswerRepository(),
        private readonly OfficialAnswerLifecycleService $lifecycle = new OfficialAnswerLifecycleService(),
    ) {
        $this->db         = $db ?? Database::connect();
        $this->queue      = new CommunityReanswerQueue($this->db, $this->answers);
        $this->automation = $automation;
    }

    public function queue(): CommunityReanswerQueue
    {
        return $this->queue;
    }

    /** Why this answer must not be re-vetted/acted on now (a repair is under way), or null. */
    public function inProgress(int $answerId): ?string
    {
        $open = $this->queue->openForAnswer($answerId);
        if ($open !== null) {
            return $open['status'] === 'queued'
                ? "Already queued for a re-answer (#{$open['id']}, {$open['mode']})."
                : "A correction is already being vetted/published (re-answer #{$open['id']}).";
        }
        $answer = $this->answers->findById($answerId);
        if ($answer === null) {
            return 'The answer no longer exists.';
        }
        if ($answer['status'] !== CommunityAnswerStatus::Published->value) {
            return "The answer is {$answer['status']} in Reach, not live; it is being handled.";
        }
        $inFlight = $this->db->table('reach_community_automation_submissions')
            ->where('question_id', (int) $answer['question_id'])->whereIn('status', ['pending', 'approved'])->countAllResults();

        return $inFlight > 0 ? 'An answer to this question is already being vetted.' : null;
    }

    /**
     * The step for a verdict: withdraw+requeue | withdraw | correct |
     * keep_live+requeue | held_high_risk | none.
     */
    public function plan(int $answerId, string $verdict, ?array $revised): string
    {
        if ($verdict === VettingResult::APPROVE) {
            return 'none';
        }
        $blocked = $this->blocked($answerId);
        if ($verdict === VettingResult::REJECT) {
            return $blocked ? 'withdraw' : 'withdraw+requeue';
        }
        if ($blocked) {
            return 'held_high_risk';
        }

        return $revised !== null ? 'correct' : 'keep_live+requeue';
    }

    /**
     * Carry out the step. Never throws: a failed step is reported as the
     * action (withdraw_failed, correction_failed+requeued, requeue_failed …).
     *
     * @param list<string>                                  $reasons
     * @param array{answer_html: string, short_answer: string}|null $revised
     * @return array{action: string, reanswer_id: ?int, error: ?string}
     */
    public function apply(int $answerId, string $verdict, ?array $revised, array $reasons): array
    {
        $step = $this->plan($answerId, $verdict, $revised);
        try {
            return match ($step) {
                'withdraw', 'withdraw+requeue' => $this->withdraw($answerId, $reasons, $step === 'withdraw+requeue'),
                'correct'                      => $this->correct($answerId, $revised ?? [], $reasons),
                'keep_live+requeue'            => $this->requeue($answerId, 'kept_live', 'revise', $reasons, 'kept_live+requeued'),
                'held_high_risk'               => ['action' => 'held_high_risk_left_as_is', 'reanswer_id' => null, 'error' => null],
                default                        => ['action' => 'none', 'reanswer_id' => null, 'error' => null],
            };
        } catch (Throwable $e) {
            log_message('error', "[CommunityAnswerRepair] {$step} on answer {$answerId} failed: " . $e->getMessage());

            return ['action' => str_replace('+requeue', '', $step) . '_failed', 'reanswer_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * The vetting model's revised answer, when the verdict carries a usable one
     * that differs from the live text; null otherwise.
     *
     * @return array{answer_html: string, short_answer: string}|null
     */
    public static function revisedText(VettingResult $result, string $currentHtml, string $currentShort): ?array
    {
        if ($result->decision !== VettingResult::REVISE) {
            return null;
        }
        $raw = trim((string) ($result->final['answer_html'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $sanitizer = new HtmlSanitizer();
        if ($sanitizer->wouldStripContent($raw)) {
            return null;
        }
        $html = trim($sanitizer->purify($raw));
        if (strlen($html) < CommunityQaPayload::MIN_ANSWER_CHARS || strlen($html) > CommunityQaPayload::MAX_ANSWER_CHARS) {
            return null;
        }
        $norm = static fn (string $h): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($norm($html) === $norm($currentHtml)) {
            return null;
        }
        $short = trim($sanitizer->purifyText((string) ($result->final['short_answer'] ?? '')));
        if ($short === '' || mb_strlen($short) > 300) {
            $short = mb_strlen($currentShort) <= 300 && $currentShort !== '' ? $currentShort : rtrim(mb_substr($norm($html), 0, 277)) . '…';
        }

        return ['answer_html' => $html, 'short_answer' => $short];
    }

    // -------------------------------------------------------------------------

    /** @param list<string> $reasons */
    private function withdraw(int $answerId, array $reasons, bool $requeue): array
    {
        $answer = $this->answers->findById($answerId);
        if ($answer === null || $answer['status'] !== CommunityAnswerStatus::Published->value) {
            return ['action' => 'withdraw_failed', 'reanswer_id' => null, 'error' => 'The answer is not live (' . ($answer['status'] ?? 'missing') . ').'];
        }
        $reason = mb_substr('Re-vetting found the live answer inaccurate: ' . implode(' ', array_slice($reasons, 0, 2)), 0, 500);

        // Off aicountly.com first; nothing changes in Reach unless the receiver confirms.
        try {
            $result = (new OfficialAnswerPublishingService())->unpublish($answerId, $reason);
        } catch (Throwable $e) {
            return ['action' => 'withdraw_failed', 'reanswer_id' => null, 'error' => $e->getMessage()];
        }
        if (! ($result['success'] ?? false)) {
            return ['action' => 'withdraw_failed', 'reanswer_id' => null,
                'error' => 'aicountly.com did not unpublish it: ' . ($result['safe_error_message'] ?? $result['error_category'] ?? 'unknown error')];
        }

        // Reach's record: withdrawn (restorable; versions and audit kept).
        $error = null;
        try {
            (new OfficialAnswerWithdrawalService($this->answers))->withdraw($answerId, $reason);
        } catch (Throwable $e) {
            $error = 'Unpublished on aicountly.com but not marked withdrawn in Reach: ' . $e->getMessage();
            log_message('error', "[CommunityAnswerRepair] {$error}");
        }
        AuditLogger::record('community.answer.revet_withdrawn', [
            'answer_id' => $answerId, 'question_id' => (int) $answer['question_id'], 'reasons' => array_slice($reasons, 0, 3),
        ]);

        if (! $requeue) {
            return ['action' => 'withdrawn', 'reanswer_id' => null, 'error' => $error ?? 'Risk tier 4 (prohibited): the desks do not re-answer it.'];
        }

        $queued = $this->requeue($answerId, 'withdrawn', 'reject', $reasons, 'withdrawn+requeued');
        if ($queued['reanswer_id'] === null) {
            return ['action' => 'withdrawn', 'reanswer_id' => null, 'error' => $queued['error']];
        }
        $this->noteOnQuestion((int) $answer['question_id'], 'Re-answer queued for the community-qa routine: the live answer was taken down after re-vetting — '
            . implode(' ', array_slice($reasons, 0, 2)));

        return array_merge($queued, ['error' => $error]);
    }

    /**
     * @param array{answer_html?: string, short_answer?: string} $revised
     * @param list<string> $reasons
     */
    private function correct(int $answerId, array $revised, array $reasons): array
    {
        $answer = $this->answers->findById($answerId);
        if ($answer === null || $answer['status'] !== CommunityAnswerStatus::Published->value) {
            return ['action' => 'correction_failed', 'reanswer_id' => null, 'error' => 'The answer is not live (' . ($answer['status'] ?? 'missing') . ').'];
        }
        $id = $this->queue->enqueue($answerId, (int) $answer['question_id'], 'kept_live', 'revise', $reasons);

        try {
            $view = $this->automation()->submitCorrection(
                $answerId,
                $id,
                (string) $revised['answer_html'],
                (string) $revised['short_answer'],
                mb_substr('vetting-revision:' . ReachConfig::vettingModel('community'), 0, 120),
            );
        } catch (Throwable $e) {
            // Nothing was changed on the answer (the correction runs in one
            // transaction); the request stays queued for a routine re-answer.
            $this->db->table(CommunityReanswerQueue::TABLE)->where('id', $id)
                ->update(['last_error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => gmdate('Y-m-d H:i:sP')]);

            return ['action' => 'correction_failed+requeued', 'reanswer_id' => $id, 'error' => $e->getMessage()];
        }

        $row = $this->queue->find($id);
        $why = ($view['reasons'] ?? []) !== [] ? implode(' ', array_slice((array) $view['reasons'], 0, 2)) : null;

        return match ($view['outcome']) {
            'published'           => ['action' => 'corrected', 'reanswer_id' => $id, 'error' => null],
            'pending', 'approved' => ['action' => 'correction_pending', 'reanswer_id' => $id, 'error' => $view['outcome'] === 'pending' ? 'Vetting unavailable; community:schedule retries it.' : 'Approved; publishing / waiting for aicountly.com to confirm it is live.'],
            default               => ['action' => ($row['status'] ?? '') === 'queued' ? 'correction_failed+requeued' : 'correction_failed',
                                      'reanswer_id' => $id, 'error' => 'Correction ' . $view['outcome'] . ($why ? ': ' . $why : '') . '; the current answer stays live.'],
        };
    }

    /** @param list<string> $reasons */
    private function requeue(int $answerId, string $mode, string $source, array $reasons, string $action): array
    {
        $answer = $this->answers->findById($answerId);
        if ($answer === null) {
            return ['action' => 'requeue_failed', 'reanswer_id' => null, 'error' => 'The answer no longer exists.'];
        }
        try {
            $id = $this->queue->enqueue($answerId, (int) $answer['question_id'], $mode, $source, $reasons);
        } catch (Throwable $e) {
            return ['action' => 'requeue_failed', 'reanswer_id' => null, 'error' => $e->getMessage()];
        }

        return ['action' => $action, 'reanswer_id' => $id, 'error' => null];
    }

    /** The Question Inbox shows why the question is waiting for the routine again. */
    private function noteOnQuestion(int $questionId, string $note): void
    {
        try {
            $this->db->table('reach_community_questions')->where('id', $questionId)
                ->groupStart()->where('handled_by IS NULL', null, false)
                    ->orWhereNotIn('handled_by', [CommunityInboxPolicy::CLOSED, CommunityInboxPolicy::CLOSED_BY_PERSON])->groupEnd()
                ->set('handled_by', CommunityInboxPolicy::QUEUE)
                ->set('handling_note', mb_substr($note, 0, 2000))
                ->set('routine_queued_at', 'COALESCE(routine_queued_at, NOW())', false)
                ->set('handling_updated_at', 'NOW()', false)
                ->update();
        } catch (Throwable $e) {
            log_message('warning', "[CommunityAnswerRepair] could not note question {$questionId}: " . $e->getMessage());
        }
    }

    private function blocked(int $answerId): bool
    {
        $answer = $this->answers->findById($answerId);

        return $answer !== null && $this->lifecycle->riskTierOf($answer)->isBlocked();
    }

    private function automation(): CommunityQaAutomationService
    {
        return $this->automation ??= new CommunityQaAutomationService($this->vetting ?? CommunityQaAutomationService::vettingService(), db: $this->db);
    }
}

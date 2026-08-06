<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityQuestionStatus;
use App\Models\CommunityOfficialAnswerModel;
use Throwable;

/**
 * Keeps a question's status in step with its official answer.
 *
 * A question is only ever a container for the official answer that Reach
 * writes for it, so its status is derived state: the two enums carry the same
 * case names on purpose. Before this service existed the only mirroring in the
 * system was the one-way hop to `draft_requested` performed when a draft answer
 * was reserved — every later answer transition (generate, validate, review,
 * approve, publish) touched the answer row alone. Questions therefore sat at
 * `draft_requested` in the inbox forever, including after their answer was live
 * on aicountly.com.
 *
 * Mirroring is best-effort by design: a question status is a reporting
 * projection, so a failure here is logged and swallowed rather than allowed to
 * abort the answer lifecycle that produced it.
 */
class CommunityQuestionStatusMirror
{
    public function __construct(
        private readonly CommunityQuestionRepository  $questionRepo = new CommunityQuestionRepository(),
        private readonly CommunityOfficialAnswerModel $answerModel  = new CommunityOfficialAnswerModel(),
    ) {}

    /**
     * The question status that corresponds to an answer status.
     * Every answer case has a same-named question case; the extra question
     * cases (duplicate_merged) have no answer equivalent and are never mirrored.
     */
    public static function questionStatusFor(CommunityAnswerStatus $answerStatus): ?CommunityQuestionStatus
    {
        return CommunityQuestionStatus::tryFrom($answerStatus->value);
    }

    /** Mirror onto the question that owns the given answer. */
    public function syncFromAnswerId(int $answerId, CommunityAnswerStatus $answerStatus): void
    {
        try {
            $answer = $this->answerModel->find($answerId);
            if ($answer === null || empty($answer['question_id'])) {
                return;
            }
            $this->sync((int) $answer['question_id'], $answerStatus);
        } catch (Throwable $e) {
            log_message('warning', 'Question status mirror skipped for answer #' . $answerId . ': ' . $e->getMessage());
        }
    }

    /**
     * Walk the question to the status matching $answerStatus.
     *
     * The two state machines are not step-for-step identical (an answer can go
     * approved -> publishing in one hop from a state the question reaches by a
     * different route), so the shortest legal route is computed and applied one
     * transition at a time. Each hop is a real transition, so audit history and
     * the CHECK constraint both stay honest.
     */
    public function sync(int $questionId, CommunityAnswerStatus $answerStatus): void
    {
        try {
            $target = self::questionStatusFor($answerStatus);
            if ($target === null) {
                return;
            }

            $question = $this->questionRepo->findById($questionId);
            if ($question === null) {
                return;
            }

            $current = CommunityQuestionStatus::tryFrom((string) ($question['status'] ?? ''));
            if ($current === null || $current === $target) {
                return;
            }

            $path = self::shortestPath($current, $target);
            if ($path === []) {
                log_message('info', sprintf(
                    'Question status mirror: no route from %s to %s for question #%d',
                    $current->value,
                    $target->value,
                    $questionId
                ));
                return;
            }

            $from = $current;
            foreach ($path as $step) {
                $this->questionRepo->transitionStatus($questionId, $from, $step);
                $from = $step;
            }
        } catch (Throwable $e) {
            log_message('warning', 'Question status mirror skipped for question #' . $questionId . ': ' . $e->getMessage());
        }
    }

    /**
     * Breadth-first search over the question state machine.
     *
     * @return list<CommunityQuestionStatus> Ordered hops excluding $from, empty when unreachable.
     */
    public static function shortestPath(CommunityQuestionStatus $from, CommunityQuestionStatus $to): array
    {
        if ($from === $to) {
            return [];
        }

        $queue   = [[$from, []]];
        $visited = [$from->value => true];

        while ($queue !== []) {
            [$node, $path] = array_shift($queue);

            foreach (CommunityQuestionStatus::validTransitions($node) as $next) {
                if (isset($visited[$next->value])) {
                    continue;
                }
                $visited[$next->value] = true;
                $nextPath = [...$path, $next];

                if ($next === $to) {
                    return $nextPath;
                }
                $queue[] = [$next, $nextPath];
            }
        }

        return [];
    }
}

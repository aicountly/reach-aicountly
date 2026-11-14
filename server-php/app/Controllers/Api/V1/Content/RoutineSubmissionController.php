<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Content;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Automation\Routine\RemediationRefused;
use App\Libraries\Automation\Routine\RoutineFlags;
use App\Libraries\Automation\Routine\RoutineRemediator;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Automation\Routine\RoutineSubmissionView;

/**
 * Claude-routine blog and KB submissions for the Blog Command Centre and the
 * KB publishing screen: the vetting decision and reasons, the cover, where it
 * is in publishing — and a Retry for the ones that stalled.
 *
 *   GET  v1/routine-submissions?content_type=blog|knowledge_base&status=…
 *   POST v1/routine-submissions/{id}/retry
 *   POST v1/routine-submissions/{id}/remedy   {step: retry|revet|rewrite, note?}
 *   POST v1/routine-submissions/fix-blocked   the bot's next step on every blocked one, now
 */
class RoutineSubmissionController extends BaseApiController
{
    public function index()
    {
        $type = (string) ($this->request->getGet('content_type') ?? '');
        if ($type !== '' && ! in_array($type, ['blog', 'knowledge_base'], true)) {
            return $this->fail('content_type must be blog or knowledge_base', 422);
        }
        $repo = new RoutineSubmissions();

        $rows = array_map(
            [RoutineSubmissionView::class, 'forUi'],
            $repo->listForUi($type !== '' ? $type : null, (string) ($this->request->getGet('status') ?? ''), (int) ($this->request->getGet('limit') ?? 50)),
        );

        $types = $type !== '' ? [$type] : ['blog', 'knowledge_base'];
        $quota = [];
        foreach ($types as $t) {
            $quota[$t] = $repo->quota($t);
        }

        return $this->ok([
            'items'           => $rows,
            'quota'           => $quota,
            'flags'           => RoutineFlags::all(),
            'auto_remediation'=> RoutineRemediator::autoEnabled(),
            'step_labels'     => RoutineRemediator::STEP_LABELS,
        ]);
    }

    public function retry(int $id)
    {
        $repo = new RoutineSubmissions();
        $row  = $repo->find($id);
        if ($row === null) {
            return $this->notFound('Routine submission not found.');
        }
        if (! in_array($row['status'], RoutineSubmissionView::RETRYABLE, true)) {
            return $this->fail("A submission that is '{$row['status']}' cannot be retried.", 409);
        }

        @set_time_limit(600);
        $after = (new RoutineContentPipeline())->retry($id);
        if (! empty($after['busy'])) {
            return $this->fail('This submission is being processed right now; try again in a few minutes.', 409);
        }

        \App\Libraries\AuditLogger::record('routine.submission_retried', [
            'submission_id' => $id, 'from' => $row['status'], 'to' => $after['status'],
        ], $this->userId());

        $view = $repo->listForUi(null, null, 1, $id)[0] ?? null;

        return $this->ok($view !== null ? RoutineSubmissionView::forUi($view) : RoutineSubmissionView::summary($after));
    }

    public function remedy(int $id)
    {
        $input = $this->input();
        $step  = (string) ($input['step'] ?? '');
        if (! isset(RoutineRemediator::STEP_LABELS[$step])) {
            return $this->fail('step must be retry, revet or rewrite', 422);
        }
        $note = isset($input['note']) ? mb_substr((string) $input['note'], 0, 500) : null;

        @set_time_limit(600);
        try {
            $after = (new RoutineRemediator())->apply($id, $step, 'operator', $this->userId(), $note);
        } catch (RemediationRefused $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 409);
        }

        $view = (new RoutineSubmissions())->listForUi(null, null, 1, $id)[0] ?? null;

        return $this->ok($view !== null ? RoutineSubmissionView::forUi($view) : RoutineSubmissionView::summary($after));
    }

    public function fixBlocked()
    {
        @set_time_limit(900);
        $result = (new RoutineRemediator())->fixAllNow($this->userId(), (int) ($this->input()['limit'] ?? 10));
        $parts  = [];
        foreach ($result['steps'] as $step => $n) {
            $parts[] = "{$n}× " . strtolower(RoutineRemediator::STEP_LABELS[$step]);
        }
        $result['message'] = $result['considered'] === 0
            ? 'Nothing is blocked any more.'
            : trim(($parts !== [] ? 'Done: ' . implode(', ', $parts) . '.' : 'No automatic step applies.')
                . ($result['left_for_a_person'] > 0 ? " {$result['left_for_a_person']} need a person — open the list for why." : '')
                . ($result['errors'] !== [] ? ' Errors: ' . implode('; ', array_slice($result['errors'], 0, 3)) : ''));

        return $this->ok($result);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Content;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Automation\Routine\RoutineFlags;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Automation\Routine\RoutineSubmissionView;

/**
 * Claude-routine blog and KB submissions for the Blog Command Centre and the
 * KB publishing screen: the vetting decision and reasons, the cover, where it
 * is in publishing — and a Retry for the ones that stalled.
 *
 *   GET  v1/routine-submissions?content_type=blog|knowledge_base&status=…
 *   POST v1/routine-submissions/{id}/retry
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
            'items' => $rows,
            'quota' => $quota,
            'flags' => RoutineFlags::all(),
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
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Automation\Structured\StructuredSubmissionService;
use App\Libraries\Automation\Structured\SubmissionRefused;

/**
 * Publishing → Release notes / Workflow guides: the routine submissions,
 * their vetting outcome, public link, and a retry for ones not yet live.
 *
 *   GET  v1/publishing/release-notes[?status=&product_slug=&limit=&offset=]
 *   GET  v1/publishing/workflow-guides[?…]
 *   POST v1/publishing/release-notes/{id}/retry
 *   POST v1/publishing/workflow-guides/{id}/retry
 */
class StructuredSubmissionController extends BaseApiController
{
    public function releaseNotes()
    {
        return $this->ok((new StructuredSubmissionService())->list('release_note', $this->filters()));
    }

    public function workflowGuides()
    {
        return $this->ok((new StructuredSubmissionService())->list('workflow_guide', $this->filters()));
    }

    public function retryReleaseNote(int $id)
    {
        return $this->retry($id, 'release_note');
    }

    public function retryWorkflowGuide(int $id)
    {
        return $this->retry($id, 'workflow_guide');
    }

    private function retry(int $id, string $type)
    {
        try {
            return $this->ok((new StructuredSubmissionService())->retry($id, $type));
        } catch (SubmissionRefused $e) {
            return $this->fail($e->getMessage(), $e->httpStatus, $e->details);
        }
    }

    /** @return array<string, string> */
    private function filters(): array
    {
        return array_filter([
            'status'       => (string) ($this->request->getGet('status') ?? ''),
            'product_slug' => (string) ($this->request->getGet('product_slug') ?? ''),
            'limit'        => (string) ($this->request->getGet('limit') ?? ''),
            'offset'       => (string) ($this->request->getGet('offset') ?? ''),
        ], static fn (string $v) => $v !== '');
    }
}

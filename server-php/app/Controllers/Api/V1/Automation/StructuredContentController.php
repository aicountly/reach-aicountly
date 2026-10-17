<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Automation;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Automation\Structured\AutomationPlanService;
use App\Libraries\Automation\Structured\StructuredSubmissionService;
use App\Libraries\Automation\Structured\SubmissionRefused;

/**
 * Release notes and workflow guides from the Claude Routines
 * (X-Automation-Token; docs/automation/routines/reach-release-notes.md and
 * reach-workflow-guides.md).
 *
 *   GET  v1/automation/release-plan     what changed since the last note, per product
 *   GET  v1/automation/workflow-plan    published guides per launch app
 *   POST v1/automation/release-notes    submit one note (vetted, then auto-published)
 *   POST v1/automation/workflow-guides  submit one guide (vetted, then auto-published)
 *
 * Responses: 201 published (data.public_url is live), 200 rejected by vetting
 * or an idempotent replay, 202 stored but not live yet (vetting unavailable
 * or publish retrying — do not resubmit), 403 automation switched off,
 * 409 version / key conflict, 422 invalid payload (details lists the fields).
 */
class StructuredContentController extends BaseApiController
{
    public function releasePlan()
    {
        return $this->ok((new AutomationPlanService())->releasePlan());
    }

    public function workflowPlan()
    {
        return $this->ok((new AutomationPlanService())->workflowPlan());
    }

    public function storeReleaseNote()
    {
        return $this->submit(fn (StructuredSubmissionService $s) => $s->submitReleaseNote($this->input()));
    }

    public function storeWorkflowGuide()
    {
        return $this->submit(fn (StructuredSubmissionService $s) => $s->submitWorkflowGuide($this->input()));
    }

    private function submit(callable $call)
    {
        try {
            $result = $call(new StructuredSubmissionService());
        } catch (SubmissionRefused $e) {
            return $this->fail($e->getMessage(), $e->httpStatus, $e->details);
        }

        return $this->ok($result['data'], $result['http']);
    }
}

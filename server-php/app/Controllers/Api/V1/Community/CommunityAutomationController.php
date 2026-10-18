<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Libraries\Community\Automation\CommunityInboxAutomationService;
use App\Libraries\Community\Automation\CommunityQaAutomationService;
use App\Libraries\Community\Automation\CommunityQaRefusal;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Community Q&A written by the reach-community-qa routine.
 *
 * X-Automation-Token (routine):
 *   GET  v1/automation/community-plan     quota, open questions, recent titles, topic mix
 *   POST v1/automation/community-qa       a frequently-asked question + the desk's answer
 *   POST v1/automation/community-answers  the desk's answer to an open (member) question
 *   GET  v1/automation/community/pending?limit=        the Question Inbox's routine queue
 *   POST v1/automation/community/pending/{uuid}/answer the desk's answer to that question (same path as community-answers)
 *   POST v1/automation/community/pending/{uuid}/decline the routine cannot answer it: the bot closes it with the reason
 *
 * JWT (Community → Automated feed):
 *   GET  v1/community/automation/feed
 *   POST v1/community/automation/submissions/{id}/retry
 */
class CommunityAutomationController extends BaseApiController
{
    public function plan(): ResponseInterface
    {
        return $this->ok((new CommunityQaAutomationService())->plan());
    }

    public function storeQa(): ResponseInterface
    {
        return $this->submit(fn (CommunityQaAutomationService $svc) => $svc->submitFaq($this->input()));
    }

    public function storeAnswer(): ResponseInterface
    {
        return $this->submit(fn (CommunityQaAutomationService $svc) => $svc->submitMemberAnswer($this->input()));
    }

    /** The inbox questions waiting for the routine, oldest/highest-priority first, and today's budget. */
    public function pending(): ResponseInterface
    {
        $limit = (int) ($this->request->getGet('limit') ?? 10);

        return $this->ok((new CommunityQaAutomationService())->budget() + [
            'questions' => (new CommunityInboxAutomationService())->pending($limit > 0 ? $limit : 10),
        ]);
    }

    /** POST v1/automation/community/pending/{uuid}/answer — the question id comes from the path. */
    public function answerPending(string $uuid): ResponseInterface
    {
        $payload                = $this->input();
        $payload['question_id'] = $uuid;

        return $this->submit(fn (CommunityQaAutomationService $svc) => $svc->submitMemberAnswer($payload));
    }

    public function declinePending(string $uuid): ResponseInterface
    {
        $reason = trim(strip_tags((string) ($this->input()['reason'] ?? '')));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return $this->fail('reason is required: one plain sentence (10–500 characters) saying why the desk cannot answer this question.', 422);
        }
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->fail('Question not found: ' . $uuid, 404);
        }

        try {
            return $this->ok((new CommunityInboxAutomationService())->decline(strtolower($uuid), $reason));
        } catch (CommunityQaRefusal $e) {
            return $this->fail($e->getMessage(), $e->httpStatus);
        }
    }

    public function feed(): ResponseInterface
    {
        $limit = (int) ($this->request->getGet('limit') ?? 50);

        return $this->ok((new CommunityQaAutomationService())->feed($limit > 0 ? $limit : 50));
    }

    /** Re-run a pending or approved submission now instead of waiting for the sweep. */
    public function retry(string $id): ResponseInterface
    {
        try {
            return $this->ok((new CommunityQaAutomationService())->process((int) $id));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        }
    }

    private function submit(callable $call): ResponseInterface
    {
        try {
            $result = $call(new CommunityQaAutomationService());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (CommunityQaRefusal $e) {
            return $this->fail($e->getMessage(), $e->httpStatus);
        }

        return $this->ok($result, $result['status'] === 'created' ? 201 : 200);
    }
}

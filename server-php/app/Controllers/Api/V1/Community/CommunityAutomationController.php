<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
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

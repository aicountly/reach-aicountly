<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Libraries\Community\OfficialAnswerRepository;
use App\Libraries\Community\OfficialAnswerGenerationService;
use App\Libraries\Community\OfficialAnswerApprovalService;
use App\Libraries\Community\OfficialAnswerPublishingService;
use App\Libraries\Community\OfficialAnswerCorrectionService;
use App\Libraries\Community\OfficialAnswerWithdrawalService;
use App\Libraries\Community\OfficialAnswerVersionService;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

class OfficialAnswerController extends BaseApiController
{
    private OfficialAnswerRepository $repo;

    public function __construct()
    {
        $this->repo = new OfficialAnswerRepository();
    }

    /** GET /community/answers — list by status/question */
    public function index(): ResponseInterface
    {
        $status  = (string) ($this->request->getGet('status') ?? '');
        $perPage = min((int) ($this->request->getGet('per_page') ?? 100), 100);

        $items = $status !== ''
            ? $this->repo->listByStatus($status, $perPage)
            : $this->repo->listByStatus('draft', $perPage);

        return $this->response->setJSON([
            'data' => $items,
            'meta' => ['total' => count($items)],
        ]);
    }

    /** GET /community/answers/(:segment) */
    public function show(string $uuid): ResponseInterface
    {
        $answer = $this->repo->findByUuid($uuid);
        if (!$answer) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
        return $this->response->setJSON(['data' => $answer]);
    }

    /** POST /community/answers — create blank answer record for a question */
    public function create(): ResponseInterface
    {
        $body         = $this->request->getJSON(true) ?? [];
        $questionUuid = $body['question_uuid'] ?? '';
        $identitySlug = $body['official_identity_slug'] ?? 'aicountly-official';

        if (empty($questionUuid)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'question_uuid required']);
        }

        $versionSvc = new OfficialAnswerVersionService();
        $answer     = $versionSvc->createBlank($questionUuid, $identitySlug);
        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_DRAFT_CREATED, ['answer_uuid' => $answer['external_id']]);
        return $this->response->setStatusCode(201)->setJSON(['data' => $answer]);
    }

    /** POST /community/answers/(:segment)/generate — trigger AI generation */
    public function generate(string $uuid): ResponseInterface
    {
        $body    = $this->request->getJSON(true) ?? [];
        $options = $body['options'] ?? [];

        try {
            $genSvc = new OfficialAnswerGenerationService();
            $result = $genSvc->generate($uuid, $options);
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_GENERATION_STARTED, ['answer_uuid' => $uuid]);
            return $this->response->setJSON(['data' => $result]);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /** PUT /community/answers/(:segment) — save human-edited content */
    public function update(string $uuid): ResponseInterface
    {
        $body    = $this->request->getJSON(true) ?? [];
        $content = $body['content'] ?? [];

        $versionSvc = new OfficialAnswerVersionService();
        $version    = $versionSvc->createVersion($uuid, $content, 'human_edit');
        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_EDITED, ['answer_uuid' => $uuid]);
        return $this->response->setJSON(['data' => $version]);
    }

    /** POST /community/answers/(:segment)/approve */
    public function approve(string $uuid): ResponseInterface
    {
        $body    = $this->request->getJSON(true) ?? [];
        $note    = $body['note'] ?? null;
        $userId  = $this->userId();

        try {
            $approvalSvc = new OfficialAnswerApprovalService();
            $approval    = $approvalSvc->approve($uuid, $userId, $note);
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_APPROVED, ['answer_uuid' => $uuid, 'approver_id' => $userId]);
            return $this->response->setJSON(['data' => $approval]);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /** POST /community/answers/(:segment)/reject */
    public function reject(string $uuid): ResponseInterface
    {
        $body    = $this->request->getJSON(true) ?? [];
        $reason  = $body['reason'] ?? '';
        $userId  = $this->userId();

        try {
            $approvalSvc = new OfficialAnswerApprovalService();
            $approvalSvc->reject($uuid, $userId, $reason);
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_APPROVAL_REJECTED, ['answer_uuid' => $uuid]);
            return $this->response->setJSON(['success' => true]);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /** POST /community/answers/(:segment)/publish */
    public function publish(string $uuid): ResponseInterface
    {
        try {
            $pubSvc = new OfficialAnswerPublishingService();
            $result = $pubSvc->publish($uuid);
            AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_PUBLISHED, ['answer_uuid' => $uuid]);
            return $this->response->setJSON(['data' => $result]);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /** POST /community/answers/(:segment)/withdraw */
    public function withdraw(string $uuid): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $reason = $body['reason'] ?? '';
        $userId = $this->userId();

        $withdrawalSvc = new OfficialAnswerWithdrawalService();
        $withdrawalSvc->withdraw($uuid, $userId, $reason);
        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_WITHDRAWN, ['answer_uuid' => $uuid, 'reason' => $reason]);
        return $this->response->setJSON(['success' => true]);
    }

    /** POST /community/answers/(:segment)/restore */
    public function restore(string $uuid): ResponseInterface
    {
        $userId        = $this->userId();
        $withdrawalSvc = new OfficialAnswerWithdrawalService();
        $withdrawalSvc->restore($uuid, $userId);
        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_RESTORED, ['answer_uuid' => $uuid]);
        return $this->response->setJSON(['success' => true]);
    }

    /** POST /community/answers/(:segment)/correct */
    public function correct(string $uuid): ResponseInterface
    {
        $body      = $this->request->getJSON(true) ?? [];
        $content   = $body['content'] ?? [];
        $note      = $body['correction_note'] ?? '';
        $userId    = $this->userId();

        $correctionSvc = new OfficialAnswerCorrectionService();
        $version       = $correctionSvc->correct($uuid, $userId, $content, $note);
        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_CORRECTED, ['answer_uuid' => $uuid, 'note' => $note]);
        return $this->response->setJSON(['data' => $version]);
    }

    /** GET /community/answers/(:segment)/versions */
    public function versions(string $uuid): ResponseInterface
    {
        $answer = $this->repo->findByUuid($uuid);
        if (!$answer) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
        $versions = $this->repo->listVersions((int) $answer['id']);
        return $this->response->setJSON(['data' => $versions]);
    }
}


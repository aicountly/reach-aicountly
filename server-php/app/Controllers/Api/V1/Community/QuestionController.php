<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\CommunityQuestionRepository;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

class QuestionController extends BaseApiController
{
    private CommunityQuestionRepository $repo;

    public function __construct()
    {
        $this->repo = new CommunityQuestionRepository();
    }

    /** GET /community/questions — paginated inbox with filters */
    public function index(): ResponseInterface
    {
        $page    = (int) ($this->request->getGet('page') ?? 1);
        $perPage = min((int) ($this->request->getGet('per_page') ?? 25), 100);
        $status  = $this->request->getGet('status');
        $spaceId = $this->request->getGet('space_id');

        $result = $this->repo->listForInbox(
            filters: array_filter(compact('status', 'spaceId')),
            page: $page,
            perPage: $perPage,
        );

        return $this->response->setJSON([
            'data' => $result['data'],
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $result['total'],
                'last_page'    => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /** GET /community/questions/(:segment) */
    public function show(string $uuid): ResponseInterface
    {
        $question = $this->repo->findByUuid($uuid);
        if (!$question) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
        return $this->response->setJSON(['data' => $question]);
    }

    /** POST /community/questions — manual intake */
    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];
        try {
            $intakeService = new CommunityQuestionIntakeService();
            $question = $intakeService->ingest($body);
            AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_INGESTED, ['question_uuid' => $question['external_id']]);
            return $this->response->setStatusCode(201)->setJSON(['data' => $question]);
        } catch (\InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /** PUT /community/questions/(:segment)/status */
    public function updateStatus(string $uuid): ResponseInterface
    {
        $body      = $this->request->getJSON(true) ?? [];
        $newStatus = $body['status'] ?? '';

        $question = $this->repo->findByUuid($uuid);
        if (!$question) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Question not found.']);
        }

        $from = \App\Enums\CommunityQuestionStatus::tryFrom($question['status']);
        $to   = \App\Enums\CommunityQuestionStatus::tryFrom($newStatus);

        if (!$from || !$to) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Invalid status value.']);
        }

        try {
            $this->repo->transitionStatus((int) $question['id'], $from, $to);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_STATUS_CHANGED, compact('uuid', 'newStatus'));
        return $this->response->setJSON(['success' => true]);
    }

    /** GET /community/questions/stats */
    public function stats(): ResponseInterface
    {
        $counts = $this->repo->countByStatus();
        return $this->response->setJSON(['data' => $counts]);
    }
}


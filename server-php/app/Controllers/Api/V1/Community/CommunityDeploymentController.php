<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseController;
use App\Models\CommunityDeploymentModel;
use App\Libraries\Community\OfficialAnswerPublishingService;
use App\Libraries\Community\CommunityPublicationVerificationService;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

class CommunityDeploymentController extends BaseController
{
    private CommunityDeploymentModel $model;

    public function __construct()
    {
        $this->model = new CommunityDeploymentModel();
    }

    /** GET /community/deployments */
    public function index(): ResponseInterface
    {
        $page    = (int) ($this->request->getGet('page') ?? 1);
        $perPage = min((int) ($this->request->getGet('per_page') ?? 25), 100);
        $offset  = ($page - 1) * $perPage;

        $db    = db_connect();
        $total = (int) $db->table('reach_community_deployments')->countAllResults();
        $rows  = $db->table('reach_community_deployments d')
            ->select('d.*, a.external_id AS answer_uuid')
            ->join('reach_community_official_answers a', 'a.id = d.answer_id', 'left')
            ->orderBy('d.created_at', 'DESC')
            ->limit($perPage, $offset)
            ->get()->getResultArray();

        return $this->response->setJSON([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /** GET /community/deployments/(:segment) */
    public function show(string $uuid): ResponseInterface
    {
        $deployment = $this->model->findByUuid($uuid);
        if (!$deployment) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
        return $this->response->setJSON(['data' => $deployment]);
    }

    /** POST /community/deployments/(:segment)/retry */
    public function retry(string $uuid): ResponseInterface
    {
        $deployment = $this->model->findByUuid($uuid);
        if (!$deployment) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }

        $pubSvc = new OfficialAnswerPublishingService();
        $result = $pubSvc->retryDeployment($deployment);
        AuditLogger::log(AuditLogger::COMMUNITY_DEPLOYMENT_RETRIED, ['deployment_uuid' => $uuid]);
        return $this->response->setJSON(['data' => $result]);
    }

    /** POST /community/deployments/(:segment)/verify */
    public function verify(string $uuid): ResponseInterface
    {
        $deployment = $this->model->findByUuid($uuid);
        if (!$deployment) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }

        $verifySvc = new CommunityPublicationVerificationService();
        $result    = $verifySvc->verify($deployment['answer_id']);
        AuditLogger::log(AuditLogger::COMMUNITY_ANSWER_VERIFICATION_RUN, ['deployment_uuid' => $uuid]);
        return $this->response->setJSON(['data' => $result]);
    }
}

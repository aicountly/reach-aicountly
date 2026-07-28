<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Models\CommunityDeploymentModel;
use App\Libraries\Community\OfficialAnswerPublishingService;
use App\Libraries\Community\CommunityPublicationVerificationService;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

class CommunityDeploymentController extends BaseApiController
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
        $empty   = [
            'data' => [],
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => 0,
                'last_page'    => 0,
            ],
        ];

        try {
            $db = db_connect();
            if (! $db->tableExists('reach_community_deployments')) {
                return $this->response->setJSON($empty);
            }

            $total = (int) $db->table('reach_community_deployments')->countAllResults();

            // Avoid table aliases in Query Builder — some CI4/Postgres
            // identifier escaping paths quote "table alias" as one name.
            $builder = $db->table('reach_community_deployments')
                ->select('reach_community_deployments.*')
                ->orderBy('reach_community_deployments.created_at', 'DESC')
                ->limit($perPage, $offset);

            if ($db->tableExists('reach_community_official_answers')) {
                $builder->select(
                    'reach_community_deployments.*, '
                    . 'reach_community_official_answers.uuid AS answer_uuid'
                )->join(
                    'reach_community_official_answers',
                    'reach_community_official_answers.id = reach_community_deployments.answer_id',
                    'left'
                );
            }

            $rows = $builder->get()->getResultArray();

            return $this->response->setJSON([
                'data' => is_array($rows) ? $rows : [],
                'meta' => [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total'        => $total,
                    'last_page'    => (int) ceil($total / max($perPage, 1)),
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'CommunityDeploymentController::index: ' . $e->getMessage());
            return $this->response->setJSON($empty);
        }
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
        AuditLogger::record(AuditLogger::COMMUNITY_DEPLOYMENT_RETRIED, ['deployment_uuid' => $uuid]);
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
        AuditLogger::record(AuditLogger::COMMUNITY_ANSWER_VERIFICATION_RUN, ['deployment_uuid' => $uuid]);
        return $this->response->setJSON(['data' => $result]);
    }
}


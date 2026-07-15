<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseController;
use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\IndexNowSubmissionService;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Models\Intelligence\IndexNowSubmissionModel;
use CodeIgniter\HTTP\ResponseInterface;

class IndexNowController extends BaseController
{
    private IndexNowSubmissionService $indexNowService;

    public function __construct()
    {
        $this->indexNowService = new IndexNowSubmissionService(
            ConnectorProviderFactory::indexNow(),
            new IndexNowSubmissionModel(),
            new AuditLogger()
        );
    }

    public function submit(): ResponseInterface
    {
        $body     = $this->request->getJSON(true) ?? [];
        $tenantId = (int) ($body['tenant_id'] ?? 1);
        $url      = trim($body['url'] ?? '');

        if (empty($url)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'url is required']);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'url must be a valid URL']);
        }

        try {
            $submission = $this->indexNowService->submitUrl($tenantId, $url, $body['content_identity_id'] ?? null);
            return $this->response->setStatusCode(201)->setJSON(['data' => $submission]);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON(['error' => $e->getMessage()]);
        }
    }

    public function submitBatch(): ResponseInterface
    {
        $body     = $this->request->getJSON(true) ?? [];
        $tenantId = (int) ($body['tenant_id'] ?? 1);
        $urls     = $body['urls'] ?? [];

        if (empty($urls) || !is_array($urls)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'urls array is required']);
        }

        try {
            $result = $this->indexNowService->submitBatch($tenantId, $urls);
            return $this->response->setJSON(['data' => $result]);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON(['error' => $e->getMessage()]);
        }
    }

    public function retryPending(): ResponseInterface
    {
        $retried = $this->indexNowService->retryPending();
        return $this->response->setJSON(['retried' => $retried, 'message' => "Retried {$retried} pending submissions"]);
    }
}

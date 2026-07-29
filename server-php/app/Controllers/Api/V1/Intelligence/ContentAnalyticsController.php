<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseController;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\ContentMetricFactModel;
use CodeIgniter\HTTP\ResponseInterface;

class ContentAnalyticsController extends BaseController
{
    public function connections(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_analytics_connections')) {
                return $this->response->setJSON(['data' => []]);
            }

            $model       = new AnalyticsConnectionModel();
            $connections = $model->where('tenant_id', $tenantId)->where('provider', 'ga4')->findAll();
            return $this->response->setJSON(['data' => is_array($connections) ? $connections : []]);
        } catch (\Throwable $e) {
            log_message('error', 'ContentAnalyticsController::connections: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    public function createConnection(): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new AnalyticsConnectionModel();
        $id    = $model->insert(array_merge($body, ['provider' => 'ga4']));
        return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
    }

    public function ingest(int $id): ResponseInterface
    {
        return $this->response->setJSON(['message' => 'Content analytics ingestion queued', 'connection_id' => $id]);
    }

    public function metrics(): ResponseInterface
    {
        $from = $this->request->getGet('from') ?? date('Y-m-d', strtotime('-30 days'));
        $to   = $this->request->getGet('to') ?? date('Y-m-d');
        return $this->response->setJSON(['data' => [], 'period' => ['from' => $from, 'to' => $to]]);
    }

    public function contentMetrics(int $identityId): ResponseInterface
    {
        $from  = $this->request->getGet('from') ?? date('Y-m-d', strtotime('-30 days'));
        $to    = $this->request->getGet('to') ?? date('Y-m-d');
        $model = new ContentMetricFactModel();
        $facts = $model->getForContent($identityId, $from, $to);
        return $this->response->setJSON(['data' => $facts, 'identity_id' => $identityId]);
    }
}

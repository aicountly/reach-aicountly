<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseController;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\SearchMetricFactModel;
use CodeIgniter\HTTP\ResponseInterface;

class SearchConsoleController extends BaseController
{
    public function connections(): ResponseInterface
    {
        $tenantId   = (int) ($this->request->getGet('tenant_id') ?? 1);
        $model      = new AnalyticsConnectionModel();
        $connections = $model->where('tenant_id', $tenantId)->where('provider', 'gsc')->findAll();
        return $this->response->setJSON(['data' => $connections]);
    }

    public function createConnection(): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new AnalyticsConnectionModel();
        $id    = $model->insert(array_merge($body, ['provider' => 'gsc']));
        return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
    }

    public function connection(int $id): ResponseInterface
    {
        $model = new AnalyticsConnectionModel();
        $conn  = $model->find($id);
        if (!$conn) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        return $this->response->setJSON(['data' => $model->redactCredentials($conn)]);
    }

    public function ingest(int $id): ResponseInterface
    {
        return $this->response->setJSON(['message' => 'Ingestion job queued', 'connection_id' => $id]);
    }

    public function backfill(int $id): ResponseInterface
    {
        $days = (int) ($this->request->getJSON(true)['days'] ?? 90);
        return $this->response->setJSON(['message' => "Backfill of {$days} days queued", 'connection_id' => $id]);
    }

    public function status(int $id): ResponseInterface
    {
        $model = new AnalyticsConnectionModel();
        $conn  = $model->find($id);
        if (!$conn) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        return $this->response->setJSON(['data' => ['connection_id' => $id, 'health_status' => $conn['health_status']]]);
    }

    public function metrics(): ResponseInterface
    {
        $model = new SearchMetricFactModel();
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $from     = $this->request->getGet('from') ?? date('Y-m-d', strtotime('-30 days'));
        $to       = $this->request->getGet('to') ?? date('Y-m-d');
        return $this->response->setJSON(['data' => [], 'period' => ['from' => $from, 'to' => $to]]);
    }
}

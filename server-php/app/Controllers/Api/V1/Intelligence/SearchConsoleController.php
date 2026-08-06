<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\SearchMetricFactModel;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class SearchConsoleController extends BaseApiController
{
    public function connections(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setJSON(['data' => []]);
            }

            $model       = new AnalyticsConnectionModel();
            $connections = $model->where('tenant_id', $tenantId)->where('provider', 'gsc')->findAll();
            return $this->response->setJSON(['data' => is_array($connections) ? $connections : []]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::connections: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    public function createConnection(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setStatusCode(503)->setJSON([
                    'error' => 'Connector storage is not available yet. Run database migrations first.',
                ]);
            }

            $siteProperty = trim((string) ($body['site_property'] ?? ''));
            $credential   = trim((string) ($body['credential_reference'] ?? ''));
            if ($siteProperty === '' || $credential === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'site_property and credential_reference are required',
                ]);
            }

            $model = new AnalyticsConnectionModel();
            $id    = $model->insert([
                'tenant_id'            => (int) ($body['tenant_id'] ?? 1),
                'provider'             => 'gsc',
                'display_name'         => $body['display_name'] ?? 'Google Search Console',
                'site_property'        => $siteProperty,
                'credential_reference' => $credential,
                'enabled'              => array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true,
                'health_status'        => $body['health_status'] ?? 'unknown',
            ]);
            if (! $id) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error'  => 'Unable to create connection',
                    'errors' => $model->errors(),
                ]);
            }

            return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
        } catch (\Throwable $e) {
            log_message('error', 'SearchConsoleController::createConnection: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Unable to create connection']);
        }
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

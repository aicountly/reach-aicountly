<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\ContentMetricFactModel;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class ContentAnalyticsController extends BaseApiController
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
            $connections = $model->where('tenant_id', $tenantId)->where('provider', 'ga4')->findAll();
            return $this->response->setJSON(['data' => is_array($connections) ? $connections : []]);
        } catch (\Throwable $e) {
            log_message('error', 'ContentAnalyticsController::connections: ' . $e->getMessage());
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

            $propertyId = trim((string) ($body['property_id'] ?? ''));
            $credential = trim((string) ($body['credential_reference'] ?? ''));
            if ($propertyId === '' || $credential === '') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'property_id and credential_reference are required',
                ]);
            }

            $model = new AnalyticsConnectionModel();
            $id    = $model->insert([
                'tenant_id'            => (int) ($body['tenant_id'] ?? 1),
                'provider'             => 'ga4',
                'display_name'         => $body['display_name'] ?? 'GA4 Content Analytics',
                'property_id'          => $propertyId,
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
            log_message('error', 'ContentAnalyticsController::createConnection: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Unable to create connection']);
        }
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

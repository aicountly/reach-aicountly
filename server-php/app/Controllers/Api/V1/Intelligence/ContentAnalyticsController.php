<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Libraries\Intelligence\ContentSearchPerformanceService;
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

    /**
     * Content Performance list: Search Console clicks / impressions per content
     * identity (ContentSearchPerformanceService). GA4 is not wired in — the
     * ingest above is a stub — so nothing here claims sessions.
     */
    public function metrics(): ResponseInterface
    {
        try {
            $service = $this->searchPerformance();
            $window  = $service->window($this->request->getGet('from'), $this->request->getGet('to'));
            if ($window['from'] > $window['to']) {
                return $this->fail('`from` must not be after `to`.', 422);
            }

            return $this->ok([
                'available'   => $window['latest'] !== null,
                'source'      => 'search_console',
                'period'      => ['from' => $window['from'], 'to' => $window['to']],
                'latest_date' => $window['latest'],
            ] + $service->list($window['from'], $window['to']));
        } catch (\Throwable $e) {
            log_message('error', 'ContentAnalyticsController::metrics: ' . $e->getMessage());
            return $this->fail('Unable to load content performance', 500);
        }
    }

    /** One identity's search rows per day × page URL, same window as metrics(). */
    public function contentMetrics(int $identityId): ResponseInterface
    {
        try {
            $service = $this->searchPerformance();
            $window  = $service->window($this->request->getGet('from'), $this->request->getGet('to'));
            if ($window['from'] > $window['to']) {
                return $this->fail('`from` must not be after `to`.', 422);
            }
            $detail = $service->detail($identityId, $window['from'], $window['to']);
            if ($detail === null) {
                return $this->fail('Content identity not found', 404);
            }

            return $this->ok([
                'identity_id' => $identityId,
                'source'      => 'search_console',
                'period'      => ['from' => $window['from'], 'to' => $window['to']],
                'latest_date' => $window['latest'],
            ] + $detail);
        } catch (\Throwable $e) {
            log_message('error', 'ContentAnalyticsController::contentMetrics: ' . $e->getMessage());
            return $this->fail('Unable to load content analytics', 500);
        }
    }

    private function searchPerformance(): ContentSearchPerformanceService
    {
        return new ContentSearchPerformanceService(null, max(1, (int) ($this->request->getGet('tenant_id') ?? 1)));
    }
}

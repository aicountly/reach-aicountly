<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\ConnectorHealthModel;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class ConnectorController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setJSON(['data' => []]);
            }

            $model = new AnalyticsConnectionModel();
            $conns = $model->where('tenant_id', $tenantId)->findAll();
            if (! is_array($conns)) {
                $conns = [];
            }

            foreach ($conns as &$c) {
                if (is_array($c)) {
                    $c = $model->redactCredentials($c);
                }
            }
            unset($c);

            return $this->response->setJSON(['data' => $conns]);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectorController::index: ' . $e->getMessage());
            // List endpoints must not hard-fail the Intelligence UI.
            return $this->response->setJSON(['data' => []]);
        }
    }

    /**
     * Create or update a connector configuration.
     * Credentials must be environment references only — never raw secrets.
     */
    public function upsert(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setStatusCode(503)->setJSON([
                    'error' => 'Connector storage is not available yet. Run database migrations first.',
                ]);
            }

            $provider = strtolower(trim((string) ($body['provider'] ?? '')));
            $allowed  = ['gsc', 'ga4', 'bing', 'indexnow'];
            if (! in_array($provider, $allowed, true)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'provider must be one of: gsc, ga4, bing, indexnow',
                ]);
            }

            $credential = trim((string) ($body['credential_reference'] ?? ''));
            if ($credential === '' || strtoupper($credential) === '[REDACTED]') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'credential_reference is required (environment variable name only)',
                ]);
            }
            if (preg_match('/^(sk-|AIza|ya29\.|xox[baprs]-)/i', $credential)
                || (str_contains($credential, ' ') && strlen($credential) > 40)
            ) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'credential_reference looks like a raw secret. Store an env var name instead.',
                ]);
            }

            $tenantId     = (int) ($body['tenant_id'] ?? 1);
            $siteProperty = trim((string) ($body['site_property'] ?? ''));
            $propertyId   = trim((string) ($body['property_id'] ?? ''));

            if ($provider === 'gsc' && $siteProperty === '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'site_property is required for gsc']);
            }
            if ($provider === 'ga4' && $propertyId === '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'property_id is required for ga4']);
            }

            $model    = new AnalyticsConnectionModel();
            $existing = null;
            $idHint   = isset($body['id']) ? (int) $body['id'] : 0;
            if ($idHint > 0) {
                $existing = $model->find($idHint);
                if ($existing && (int) ($existing['tenant_id'] ?? 0) !== $tenantId) {
                    $existing = null;
                }
            }
            if (! $existing) {
                $builder = $model->where('tenant_id', $tenantId)->where('provider', $provider);
                if ($provider === 'gsc') {
                    $builder->where('site_property', $siteProperty);
                }
                $existing = $builder->first();
            }

            $payload = [
                'tenant_id'            => $tenantId,
                'provider'             => $provider,
                'display_name'         => trim((string) ($body['display_name'] ?? '')) ?: match ($provider) {
                    'gsc'      => 'Google Search Console',
                    'ga4'      => 'GA4 Content Analytics',
                    'indexnow' => 'IndexNow',
                    default    => strtoupper($provider),
                },
                'site_property'        => $siteProperty !== '' ? $siteProperty : null,
                'property_id'          => $propertyId !== '' ? $propertyId : null,
                'credential_reference' => $credential,
                'enabled'              => array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true,
                'health_status'        => $body['health_status'] ?? ($existing['health_status'] ?? 'unknown'),
            ];
            if (! empty($payload['enabled'])) {
                $payload['enabled_at']  = date('Y-m-d H:i:s');
                $payload['disabled_at'] = null;
            }

            if ($existing) {
                $model->update((int) $existing['id'], $payload);
                $row = $model->find((int) $existing['id']);
                return $this->response->setJSON(['data' => $model->redactCredentials($row)]);
            }

            $id = $model->insert($payload);
            if (! $id) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error'  => 'Unable to save connector',
                    'errors' => $model->errors(),
                ]);
            }

            $row = $model->find($id);
            return $this->response->setStatusCode(201)->setJSON(['data' => $model->redactCredentials($row)]);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectorController::upsert: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Unable to save connector']);
        }
    }

    public function show(int $id): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            $model = new AnalyticsConnectionModel();
            $conn  = $model->find($id);
            if (! $conn) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            return $this->response->setJSON(['data' => $model->redactCredentials($conn)]);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectorController::show: ' . $e->getMessage());
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
    }

    public function healthCheck(int $id): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            $model = new AnalyticsConnectionModel();
            $conn  = $model->find($id);
            if (! $conn) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            $health = [
                'connection_id' => $id,
                'status'        => 'unknown',
                'checked_at'    => date('Y-m-d H:i:s'),
            ];

            if (SchemaGuard::hasTable($db, 'reach_connector_health')) {
                $healthModel = new ConnectorHealthModel();
                $healthId    = $healthModel->insert($health);
                $health      = $healthModel->find($healthId) ?: $health;
            }

            try {
                $model->update($id, ['last_health_check_at' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $e) {
                log_message('error', 'ConnectorController::healthCheck update: ' . $e->getMessage());
            }

            try {
                (new AuditLogger())->log(
                    null,
                    AuditLogger::CONNECTOR_HEALTH_CHECK_PASSED,
                    'analytics_connection',
                    $id,
                    null,
                    null,
                    null,
                    'system'
                );
            } catch (\Throwable $e) {
                log_message('error', 'ConnectorController::healthCheck audit: ' . $e->getMessage());
            }

            return $this->response->setJSON(['data' => $health]);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectorController::healthCheck: ' . $e->getMessage());
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
    }

    public function disable(int $id): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            $model = new AnalyticsConnectionModel();
            $conn  = $model->find($id);
            if (! $conn) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            $model->update($id, ['enabled' => false, 'disabled_at' => date('Y-m-d H:i:s')]);

            try {
                (new AuditLogger())->log(
                    null,
                    AuditLogger::ANALYTICS_CONNECTION_REVOKED,
                    'analytics_connection',
                    $id,
                    null,
                    null,
                    null,
                    'human'
                );
            } catch (\Throwable $e) {
                log_message('error', 'ConnectorController::disable audit: ' . $e->getMessage());
            }

            return $this->response->setJSON(['message' => 'Connector disabled']);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectorController::disable: ' . $e->getMessage());
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
    }

    public function enable(int $id): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_analytics_connections')) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            $model = new AnalyticsConnectionModel();
            $conn  = $model->find($id);
            if (! $conn) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            $model->update($id, [
                'enabled'     => true,
                'enabled_at'  => date('Y-m-d H:i:s'),
                'disabled_at' => null,
            ]);

            return $this->response->setJSON(['data' => $model->redactCredentials($model->find($id))]);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectorController::enable: ' . $e->getMessage());
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
    }
}

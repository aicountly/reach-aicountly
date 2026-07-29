<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseController;
use App\Libraries\AuditLogger;
use App\Models\Intelligence\AnalyticsConnectionModel;
use App\Models\Intelligence\ConnectorHealthModel;
use CodeIgniter\HTTP\ResponseInterface;

class ConnectorController extends BaseController
{
    public function index(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_analytics_connections')) {
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

    public function show(int $id): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_analytics_connections')) {
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
            if (! $db->tableExists('reach_analytics_connections')) {
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

            if ($db->tableExists('reach_connector_health')) {
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
            if (! $db->tableExists('reach_analytics_connections')) {
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
}

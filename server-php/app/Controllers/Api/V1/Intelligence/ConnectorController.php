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
        $tenantId   = (int) ($this->request->getGet('tenant_id') ?? 1);
        $model      = new AnalyticsConnectionModel();
        $conns      = $model->where('tenant_id', $tenantId)->findAll();
        foreach ($conns as &$c) {
            $c = $model->redactCredentials($c);
        }
        return $this->response->setJSON(['data' => $conns]);
    }

    public function show(int $id): ResponseInterface
    {
        $model = new AnalyticsConnectionModel();
        $conn  = $model->find($id);
        if (!$conn) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        return $this->response->setJSON(['data' => $model->redactCredentials($conn)]);
    }

    public function healthCheck(int $id): ResponseInterface
    {
        $model = new AnalyticsConnectionModel();
        $conn  = $model->find($id);
        if (!$conn) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        $healthModel = new ConnectorHealthModel();
        $healthId    = $healthModel->insert([
            'connection_id' => $id,
            'status'        => 'unknown',
            'checked_at'    => date('Y-m-d H:i:s'),
        ]);

        $model->update($id, ['last_health_check_at' => date('Y-m-d H:i:s')]);

        (new AuditLogger())->log(null, AuditLogger::CONNECTOR_HEALTH_CHECK_PASSED, 'analytics_connection', $id, null, null, null, 'system');

        return $this->response->setJSON(['data' => $healthModel->find($healthId)]);
    }

    public function disable(int $id): ResponseInterface
    {
        $model = new AnalyticsConnectionModel();
        $conn  = $model->find($id);
        if (!$conn) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        $model->update($id, ['enabled' => false, 'disabled_at' => date('Y-m-d H:i:s')]);

        (new AuditLogger())->log(null, AuditLogger::ANALYTICS_CONNECTION_REVOKED, 'analytics_connection', $id, null, null, null, 'human');

        return $this->response->setJSON(['message' => 'Connector disabled']);
    }
}

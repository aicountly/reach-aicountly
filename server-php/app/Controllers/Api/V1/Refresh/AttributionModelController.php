<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Refresh;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Refresh\AttributionModelService;
use App\Models\Refresh\AttributionModelModel;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class AttributionModelController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $catalog  = AttributionModelService::catalog();
        $byName   = [];

        try {
            $db = \Config\Database::connect();
            if (SchemaGuard::hasTable($db, 'reach_attribution_models')) {
                $model = new AttributionModelModel();
                $rows  = $model->where('tenant_id', $tenantId)->findAll();
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (is_array($row) && isset($row['model_name'])) {
                            $byName[$row['model_name']] = $row;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'AttributionModelController::index: ' . $e->getMessage());
        }

        $data = array_map(static function (array $item) use ($byName): array {
            $existing = $byName[$item['model_name']] ?? null;
            return [
                ...$item,
                'id'                   => $existing['id'] ?? null,
                'lookback_window_days' => isset($existing['lookback_window_days'])
                    ? (int) $existing['lookback_window_days']
                    : 30,
                'is_active'            => (bool) ($existing['is_active'] ?? false),
                'registered'           => $existing !== null,
                'created_at'           => $existing['created_at'] ?? null,
            ];
        }, $catalog);

        return $this->response->setJSON(['data' => $data]);
    }

    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_attribution_models')) {
                return $this->response->setStatusCode(503)->setJSON([
                    'error' => 'Attribution model storage is not available yet. Run database migrations first.',
                ]);
            }

            $modelName = trim((string) ($body['model_name'] ?? ''));
            $defs      = AttributionModelService::catalog();
            $def       = null;
            foreach ($defs as $item) {
                if ($item['model_name'] === $modelName) {
                    $def = $item;
                    break;
                }
            }
            if ($def === null) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'model_name must be equal_weight, position_based, or time_decay',
                ]);
            }

            $tenantId    = (int) ($body['tenant_id'] ?? 1);
            $lookback    = max(1, (int) ($body['lookback_window_days'] ?? 30));
            $model       = new AttributionModelModel();
            $existing    = $model->where('tenant_id', $tenantId)->where('model_name', $modelName)->first();
            if ($existing) {
                return $this->response->setJSON(['data' => $existing]);
            }

            $id = $model->insert([
                'tenant_id'            => $tenantId,
                'model_name'           => $modelName,
                'description'          => $def['description'],
                'formula'              => $def['formula'],
                'lookback_window_days' => $lookback,
                'limitations'          => $def['limitations'],
                'is_active'            => false,
            ]);
            if (! $id) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error'  => 'Unable to register attribution model',
                    'errors' => $model->errors(),
                ]);
            }

            try {
                (new AuditLogger())->log(
                    null,
                    AuditLogger::ATTRIBUTION_MODEL_CREATED,
                    'attribution_model',
                    (int) $id,
                    null,
                    $model->find($id),
                    null,
                    'human'
                );
            } catch (\Throwable $e) {
                log_message('error', 'AttributionModelController::create audit: ' . $e->getMessage());
            }

            return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
        } catch (\Throwable $e) {
            log_message('error', 'AttributionModelController::create: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Unable to register attribution model']);
        }
    }

    public function activate(int $id): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_attribution_models')) {
                return $this->response->setStatusCode(503)->setJSON([
                    'error' => 'Attribution model storage is not available yet. Run database migrations first.',
                ]);
            }

            $model = new AttributionModelModel();
            $row   = $model->find($id);
            if (! $row) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
            }

            $model->update($id, ['is_active' => true]);

            try {
                (new AuditLogger())->log(
                    null,
                    AuditLogger::ATTRIBUTION_MODEL_APPROVED,
                    'attribution_model',
                    $id,
                    null,
                    $model->find($id),
                    null,
                    'human'
                );
            } catch (\Throwable $e) {
                log_message('error', 'AttributionModelController::activate audit: ' . $e->getMessage());
            }

            return $this->response->setJSON(['data' => $model->find($id)]);
        } catch (\Throwable $e) {
            log_message('error', 'AttributionModelController::activate: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Unable to activate attribution model']);
        }
    }
}

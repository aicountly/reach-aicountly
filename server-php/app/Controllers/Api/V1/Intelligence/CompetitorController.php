<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Models\Intelligence\CompetitorModel;
use App\Models\Intelligence\CompetitorAliasModel;
use App\Models\Intelligence\CompetitorObservationAggregateModel;
use CodeIgniter\HTTP\ResponseInterface;

class CompetitorController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_competitors')) {
                return $this->response->setJSON(['data' => []]);
            }

            $model = new CompetitorModel();
            $rows  = $model->getActiveForTenant($tenantId);
            return $this->response->setJSON(['data' => is_array($rows) ? $rows : []]);
        } catch (\Throwable $e) {
            log_message('error', 'CompetitorController::index: ' . $e->getMessage());
            return $this->response->setJSON(['data' => []]);
        }
    }

    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_competitors')) {
                return $this->response->setStatusCode(503)->setJSON([
                    'error' => 'Competitor storage is not available yet. Run database migrations first.',
                ]);
            }

            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'name is required']);
            }

            $payload = [
                'tenant_id'          => (int) ($body['tenant_id'] ?? 1),
                'name'               => $name,
                'legal_name'         => $body['legal_name'] ?? null,
                'website_domain'     => $body['website_domain'] ?? $body['domain'] ?? null,
                'category'           => $body['category'] ?? 'general',
                'monitoring_enabled' => array_key_exists('monitoring_enabled', $body)
                    ? (bool) $body['monitoring_enabled']
                    : true,
                'monitoring_status'  => $body['monitoring_status'] ?? 'active',
                'notes'              => $body['notes'] ?? null,
            ];

            $model = new CompetitorModel();
            $id    = $model->insert($payload);
            if (! $id) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error'  => 'Unable to create competitor',
                    'errors' => $model->errors(),
                ]);
            }

            try {
                (new AuditLogger())->log(
                    null,
                    AuditLogger::COMPETITOR_CREATED,
                    'competitor',
                    (int) $id,
                    null,
                    $model->find($id),
                    null,
                    'human'
                );
            } catch (\Throwable $e) {
                log_message('error', 'CompetitorController::create audit: ' . $e->getMessage());
            }

            return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
        } catch (\Throwable $e) {
            log_message('error', 'CompetitorController::create: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Unable to create competitor']);
        }
    }

    public function show(int $id): ResponseInterface
    {
        $model      = new CompetitorModel();
        $competitor = $model->find($id);
        if (!$competitor) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        $aliasModel = new CompetitorAliasModel();
        $aliases    = $aliasModel->where('competitor_id', $id)->findAll();

        return $this->response->setJSON(['data' => array_merge($competitor, ['aliases' => $aliases])]);
    }

    public function update(int $id): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new CompetitorModel();
        $comp  = $model->find($id);
        if (!$comp) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);

        $model->update($id, $body);
        (new AuditLogger())->log(null, AuditLogger::COMPETITOR_UPDATED, 'competitor', $id, $comp, $model->find($id), null, 'human');

        return $this->response->setJSON(['data' => $model->find($id)]);
    }

    public function addAlias(int $id): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $model = new CompetitorAliasModel();

        $existing = $model->where('alias_value', $body['alias_value'] ?? '')->first();
        if ($existing && $existing['competitor_id'] !== $id) {
            (new AuditLogger())->log(null, AuditLogger::COMPETITOR_ALIAS_CONFLICT, 'competitor_alias', $id,
                null, null, ['alias' => $body['alias_value'], 'conflict_with' => $existing['competitor_id']], 'system');
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Alias already used by another competitor']);
        }

        $aliasId = $model->insert(array_merge(['competitor_id' => $id], $body));
        (new AuditLogger())->log(null, AuditLogger::COMPETITOR_ALIAS_ADDED, 'competitor_alias', (int)$aliasId, null, null, null, 'human');

        return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($aliasId)]);
    }

    public function observations(int $id): ResponseInterface
    {
        $model = new CompetitorObservationAggregateModel();
        $data  = $model->where('competitor_id', $id)->orderBy('period_start', 'DESC')->findAll();
        foreach ($data as &$row) {
            $row['_disclosure'] = $row['sample_scope_note'];
        }
        return $this->response->setJSON(['data' => $data]);
    }
}

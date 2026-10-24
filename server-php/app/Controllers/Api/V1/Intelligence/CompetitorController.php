<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\Visibility\VisibilityRecordsService;
use App\Models\Intelligence\CompetitorModel;
use App\Models\Intelligence\CompetitorAliasModel;
use App\Models\Intelligence\CompetitorObservationAggregateModel;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class CompetitorController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_competitors')) {
                return $this->response->setJSON(['data' => []]);
            }

            $model = new CompetitorModel();
            $rows  = $model->getActiveForTenant($tenantId);
            $rows  = is_array($rows) ? $rows : [];

            return $this->response->setJSON(['data' => $this->withRates($db, $tenantId, $rows, $this->days())]);
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
            if (! SchemaGuard::hasTable($db, 'reach_competitors')) {
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
        $db         = \Config\Database::connect();
        $row        = $this->withRates($db, (int) $competitor['tenant_id'], [$competitor], $this->days())[0];

        return $this->response->setJSON(['data' => array_merge($row, ['aliases' => $aliases])]);
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

    /**
     * GET intelligence/competitors/{id}/observations?days=28 — the answers
     * that named this competitor (not in the question): the numerator of its
     * sample mention rate. Stored per-prompt aggregates ride along.
     */
    public function observations(int $id): ResponseInterface
    {
        $competitor = (new CompetitorModel())->find($id);
        if (! $competitor) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }

        $db         = \Config\Database::connect();
        $aggregates = [];
        if (SchemaGuard::hasTable($db, 'reach_competitor_observation_aggregates')) {
            $aggregates = (new CompetitorObservationAggregateModel())->where('competitor_id', $id)->orderBy('period_start', 'DESC')->findAll();
            foreach ($aggregates as &$row) {
                $row['_disclosure'] = $row['sample_scope_note'];
            }
            unset($row);
        }

        if (! SchemaGuard::hasTable($db, 'reach_ai_visibility_observations')) {
            return $this->response->setJSON(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 50, 'filters' => []], 'aggregates' => $aggregates]);
        }

        $result = (new VisibilityRecordsService($db, (int) $competitor['tenant_id']))->observations(
            ['competitor_id' => $id, 'coverage_state' => 'mentioned', 'counted' => 1, 'days' => $this->days()]
                + array_intersect_key($this->request->getGet() ?? [], array_flip(['provider', 'prompt_id'])),
            (int) ($this->request->getGet('page') ?? 1),
            (int) ($this->request->getGet('per_page') ?? 50),
        );

        return $this->response->setJSON([
            'data'       => $result['rows'],
            'meta'       => array_diff_key($result, ['rows' => true]),
            'aggregates' => $aggregates,
        ]);
    }

    private function days(): int
    {
        $days = (int) ($this->request->getGet('days') ?? 28);

        return $days > 0 ? min(180, $days) : 28;
    }

    /**
     * Live sample mention rate from stored AI answers (the aggregates table
     * is not populated by any job, so it cannot back the number).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withRates(\CodeIgniter\Database\BaseConnection $db, int $tenantId, array $rows, int $days): array
    {
        if ($rows === [] || ! SchemaGuard::hasTable($db, 'reach_ai_visibility_observations')) {
            return $rows;
        }
        try {
            $rates = (new VisibilityRecordsService($db, $tenantId))->competitorRates($days, array_map('intval', array_column($rows, 'id')));
        } catch (\Throwable $e) {
            log_message('error', 'CompetitorController::withRates: ' . $e->getMessage());
            return $rows;
        }

        return array_map(static function (array $r) use ($rates): array {
            $rate = $rates[(int) $r['id']] ?? null;
            if ($rate === null || $rate['answers'] === 0) {
                return $r + ['mention_rate' => null, 'mentions' => 0, 'answers' => $rate['answers'] ?? 0, 'rate_days' => $rate['days'] ?? null];
            }

            return $r + ['mention_rate' => $rate['mention_rate'], 'mentions' => $rate['mentions'], 'answers' => $rate['answers'], 'rate_days' => $rate['days']];
        }, $rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Refresh;

use App\Controllers\BaseApiController;
use App\Libraries\Refresh\RefreshBacklogReadService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Refresh recommendations and workflows behind the readiness Operations
 * dashboard's "Recommendation Backlog" and "Active Workflows" cards.
 *
 *   GET v1/readiness/recommendations       ?status=&risk=&content_identity_id=&backlog=1
 *   GET v1/readiness/recommendations/{id}  score components, evidence, workflows
 *   GET v1/readiness/workflows             ?status=&active=1&content_identity_id=&recommendation_id=
 *
 * tenant_id defaults to 1, like OperationsController::summary, so the counts
 * and these lists share a scope.
 */
class RefreshRecommendationController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$page, $limit, $offset] = $this->pagination();
        $filters = [
            'status'              => $this->listParam('status'),
            'risk'                => $this->listParam('risk'),
            'content_identity_id' => (int) ($this->request->getGet('content_identity_id') ?? 0),
            'backlog'             => (string) $this->request->getGet('backlog') === '1',
        ];

        try {
            $result = (new RefreshBacklogReadService())->recommendations($this->tenantId(), $filters, $limit, $offset);
        } catch (\Throwable $e) {
            log_message('error', 'RefreshRecommendationController::index: ' . $e->getMessage());
            return $this->fail('Unable to load refresh recommendations', 500);
        }

        return $this->ok($result + ['page' => $page, 'limit' => $limit]);
    }

    public function show(int $id): ResponseInterface
    {
        try {
            $row = (new RefreshBacklogReadService())->recommendation($id);
        } catch (\Throwable $e) {
            log_message('error', 'RefreshRecommendationController::show: ' . $e->getMessage());
            return $this->fail('Unable to load refresh recommendation', 500);
        }

        return $row === null ? $this->fail('Recommendation not found', 404) : $this->ok($row);
    }

    public function workflows(): ResponseInterface
    {
        [$page, $limit, $offset] = $this->pagination();
        $filters = [
            'status'              => $this->listParam('status'),
            'active'              => (string) $this->request->getGet('active') === '1',
            'content_identity_id' => (int) ($this->request->getGet('content_identity_id') ?? 0),
            'recommendation_id'   => (int) ($this->request->getGet('recommendation_id') ?? 0),
        ];

        try {
            $result = (new RefreshBacklogReadService())->workflows($this->tenantId(), $filters, $limit, $offset);
        } catch (\Throwable $e) {
            log_message('error', 'RefreshRecommendationController::workflows: ' . $e->getMessage());
            return $this->fail('Unable to load refresh workflows', 500);
        }

        return $this->ok($result + ['page' => $page, 'limit' => $limit]);
    }

    private function tenantId(): int
    {
        return max(1, (int) ($this->request->getGet('tenant_id') ?? 1));
    }

    /** @return list<string> comma list; values are whitelisted by the service */
    private function listParam(string $key): array
    {
        $raw = $this->request->getGet($key);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($raw))))));
    }
}

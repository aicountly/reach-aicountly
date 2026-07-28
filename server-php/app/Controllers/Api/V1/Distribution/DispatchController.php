<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\CampaignReconciliationService;
use App\Libraries\JobService;
use App\Models\Distribution\CampaignDispatchModel;
use CodeIgniter\HTTP\ResponseInterface;

class DispatchController extends BaseApiController
{
    private CampaignDispatchModel $model;
    private CampaignReconciliationService $reconciliation;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->model = new CampaignDispatchModel();
        $this->reconciliation = new CampaignReconciliationService(
            new JobService(),
            new AuditLogger(),
        );
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    /**
     * GET /v1/distribution/dispatches
     */
    public function index(): ResponseInterface
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = min(100, max(1, (int) ($this->request->getGet('per_page') ?? $this->request->getGet('limit') ?? 25)));

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_campaign_dispatches')) {
                return $this->ok([
                    'data'     => [],
                    'total'    => 0,
                    'page'     => $page,
                    'per_page' => $perPage,
                ]);
            }

            $offset   = ($page - 1) * $perPage;
            $tenantId = $this->tenantId();

            $q = $this->model->where('tenant_id', $tenantId);

            $channel = trim((string) $this->request->getGet('channel'));
            if ($channel !== '') {
                $q->where('channel', $channel);
            }

            $status = trim((string) $this->request->getGet('status'));
            if ($status !== '') {
                $q->where('status', $status);
            }

            $total = $q->countAllResults(false);
            $rows  = $q->orderBy('created_at', 'DESC')->findAll($perPage, $offset);

            // Frontend column label uses delivered_count; DB stores sent_count.
            $rows = array_map(static function (array $row): array {
                $row['delivered_count'] = (int) ($row['sent_count'] ?? 0);
                return $row;
            }, $rows);

            return $this->ok([
                'data'     => $rows,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'DispatchController::index: ' . $e->getMessage());
            return $this->ok([
                'data'     => [],
                'total'    => 0,
                'page'     => $page,
                'per_page' => $perPage,
            ]);
        }
    }

    /**
     * GET /distribution/dispatches/:id
     */
    public function show(string $id): ResponseInterface
    {
        $row = $this->model->find((int) $id);
        if ($row === null || (int) ($row['tenant_id'] ?? 0) !== $this->tenantId()) {
            return $this->fail('Dispatch not found.', 404);
        }
        $row['delivered_count'] = (int) ($row['sent_count'] ?? 0);
        return $this->ok($row);
    }

    /**
     * POST /distribution/dispatches/:id/reconcile
     */
    public function reconcile(string $id): ResponseInterface
    {
        try {
            $result = $this->reconciliation->reconcileDispatch(
                (int) $id,
                $this->tenantId(),
                $this->userId(),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            return $this->fail('Reconciliation failed: ' . $e->getMessage(), 500);
        }

        return $this->ok($result, 202);
    }
}

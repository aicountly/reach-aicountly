<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Publishing\Jobs\PublicationRetryService;
use App\Libraries\Publishing\Jobs\PublicationVerificationService;
use App\Libraries\Publishing\Jobs\PublicationRollbackService;

class DeploymentController extends BaseApiController
{
    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return \Config\Database::connect();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $page   = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $emptyMeta = [
            'total'     => 0,
            'page'      => $page,
            'per_page'  => $limit,
            'last_page' => 1,
        ];

        try {
            $db = $this->db();

            if (
                ! $db->tableExists('reach_publication_deployments')
                || ! $db->tableExists('reach_content_items')
            ) {
                return $this->ok([], $emptyMeta);
            }

            $total = $db->table('reach_publication_deployments')->countAllResults();

            // Avoid table aliases in Query Builder — some CI4/Postgres
            // identifier escaping paths quote "table alias" as one name.
            $rows = $db->table('reach_publication_deployments')
                ->select(
                    'reach_publication_deployments.*, '
                    . 'reach_content_items.title AS content_title, '
                    . 'reach_content_items.content_type'
                )
                ->join(
                    'reach_content_items',
                    'reach_content_items.id = reach_publication_deployments.content_item_id',
                    'left'
                )
                ->orderBy('reach_publication_deployments.updated_at', 'DESC')
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();

            return $this->ok(is_array($rows) ? $rows : [], [
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $limit,
                'last_page' => max(1, (int) ceil($total / $limit)),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'DeploymentController::index: ' . $e->getMessage());
            // List endpoints must not hard-fail the Publishing UI.
            return $this->ok([], $emptyMeta);
        }
    }

    public function show(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $db = $this->db();

            if (
                ! $db->tableExists('reach_publication_deployments')
                || ! $db->tableExists('reach_content_items')
            ) {
                return $this->notFound('Deployment not found');
            }

            // Avoid table aliases in Query Builder — some CI4/Postgres
            // identifier escaping paths quote "table alias" as one name.
            $row = $db->table('reach_publication_deployments')
                ->select(
                    'reach_publication_deployments.*, '
                    . 'reach_content_items.title AS content_title, '
                    . 'reach_content_items.content_type'
                )
                ->join(
                    'reach_content_items',
                    'reach_content_items.id = reach_publication_deployments.content_item_id',
                    'left'
                )
                ->where('reach_publication_deployments.id', $id)
                ->get()
                ->getRowArray();
        } catch (\Throwable $e) {
            log_message('error', 'DeploymentController::show: ' . $e->getMessage());
            return $this->notFound('Deployment not found');
        }

        if (! $row) {
            return $this->notFound('Deployment not found');
        }

        return $this->ok($row);
    }

    public function verifications(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $db = $this->db();
            if (! $db->tableExists('reach_publication_verifications')) {
                return $this->ok([]);
            }

            $rows = $db->table('reach_publication_verifications')
                ->where('deployment_id', $id)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'DeploymentController::verifications: ' . $e->getMessage());
            return $this->ok([]);
        }
    }

    public function retry(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            (new PublicationRetryService())->scheduleRetry($id);
            return $this->ok(['scheduled' => true]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function cancel(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $actor = $this->request->actor ?? null;
        (new PublicationRetryService())->cancel($id, $actor?->id);
        return $this->ok(['cancelled' => true]);
    }

    public function verify(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $result = (new PublicationVerificationService())->verify($id);
            return $this->ok($result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function rollback(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $reason = $body['reason'] ?? 'Manual rollback';
        $actor  = $this->request->actor ?? null;

        try {
            $success = (new PublicationRollbackService())->rollback($id, $reason, $actor?->id);
            return $this->ok(['rolled_back' => $success]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

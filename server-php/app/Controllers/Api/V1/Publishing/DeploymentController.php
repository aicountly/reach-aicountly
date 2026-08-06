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

        $db = $this->db();

        // Uncached: the cached variant answers from a per-connection
        // listTables() snapshot, so a table created after the connection
        // warmed up reads as missing — and this endpoint reports "missing" as
        // an empty deployment list, which is indistinguishable from "healthy".
        if (
            ! $db->tableExists('reach_publication_deployments', false)
            || ! $db->tableExists('reach_content_items', false)
        ) {
            return $this->ok([], $emptyMeta);
        }

        // Avoid table aliases in Query Builder — some CI4/Postgres
        // identifier escaping paths quote "table alias" as one name.
        $builder = $db->table('reach_publication_deployments')
            ->select(
                'reach_publication_deployments.*, '
                . 'reach_content_items.title AS content_title, '
                . 'reach_content_items.slug AS content_slug, '
                . 'reach_content_items.content_type'
            )
            ->join(
                'reach_content_items',
                'reach_content_items.id = reach_publication_deployments.content_item_id',
                'left'
            );

        // Without these a red "22 failed" dashboard card had no list behind
        // it: 25 rows a page, newest first, no way to isolate the failures.
        $status = trim((string) $this->request->getGet('status'));
        if ($status !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $status))));
            if ($statuses !== []) {
                $builder->whereIn('reach_publication_deployments.status', $statuses);
            }
        }

        $contentType = trim((string) $this->request->getGet('content_type'));
        if ($contentType !== '') {
            $builder->where('reach_content_items.content_type', $contentType);
        }

        try {
            $total = $builder->countAllResults(false);
            $rows  = $builder
                ->orderBy('reach_publication_deployments.updated_at', 'DESC')
                ->limit($limit, $offset)
                ->get()
                ->getResultArray();
        } catch (\Throwable $e) {
            // Previously this returned an empty list, so a broken query read as
            // "No deployments yet" — the UI cannot tell a healthy empty queue
            // from a failure it should be shouting about. Say what happened.
            log_message('error', 'DeploymentController::index: ' . $e->getMessage());

            return $this->error('Could not load deployments.', 500);
        }

        return $this->ok(is_array($rows) ? $rows : [], [
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $limit,
            'last_page' => max(1, (int) ceil($total / $limit)),
        ]);
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

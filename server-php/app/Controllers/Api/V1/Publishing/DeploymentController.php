<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Publishing\Jobs\PublicationRetryService;
use App\Libraries\Publishing\Jobs\PublicationVerificationService;
use App\Libraries\Publishing\Jobs\PublicationRollbackService;

class DeploymentController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $page  = (int) ($this->request->getGet('page') ?? 1);
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $total = $this->db->table('reach_publication_deployments')->countAllResults(false);

        $rows = $this->db->table('reach_publication_deployments d')
            ->select('d.*, ci.title AS content_title, ci.content_type')
            ->join('reach_content_items ci', 'ci.id = d.content_item_id', 'left')
            ->orderBy('d.updated_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        return $this->ok($rows, [
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $limit,
            'last_page' => max(1, (int) ceil($total / $limit)),
        ]);
    }

    public function show(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $row = $this->db->table('reach_publication_deployments d')
            ->select('d.*, ci.title AS content_title, ci.content_type')
            ->join('reach_content_items ci', 'ci.id = d.content_item_id', 'left')
            ->where('d.id', $id)
            ->get()->getRowArray();

        if (!$row) {
            return $this->notFound('Deployment not found');
        }

        return $this->ok($row);
    }

    public function verifications(int $id): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = $this->db->table('reach_publication_verifications')
            ->where('deployment_id', $id)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return $this->ok($rows);
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

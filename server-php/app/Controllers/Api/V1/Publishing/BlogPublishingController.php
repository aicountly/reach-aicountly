<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;

class BlogPublishingController extends BaseApiController
{
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $db = \Config\Database::connect();

            if (
                ! $db->tableExists('reach_publication_deployments')
                || ! $db->tableExists('reach_content_items')
            ) {
                return $this->ok([]);
            }

            // Avoid table aliases in Query Builder — some CI4/Postgres
            // identifier escaping paths quote "table alias" as one name.
            $rows = $db->table('reach_publication_deployments')
                ->select('reach_publication_deployments.*, reach_content_items.title AS content_title')
                ->join(
                    'reach_content_items',
                    'reach_content_items.id = reach_publication_deployments.content_item_id',
                    'left'
                )
                ->where('reach_content_items.content_type', 'blog')
                ->orderBy('reach_publication_deployments.updated_at', 'DESC')
                ->limit(50)
                ->get()
                ->getResultArray();

            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'BlogPublishingController::index: ' . $e->getMessage());
            // List endpoints must not hard-fail the Publishing UI.
            return $this->ok([]);
        }
    }
}

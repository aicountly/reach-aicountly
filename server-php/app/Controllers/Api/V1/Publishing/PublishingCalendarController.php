<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Database\SchemaGuard;

class PublishingCalendarController extends BaseApiController
{
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $db = \Config\Database::connect();

            if (
                ! SchemaGuard::hasTable($db, 'reach_publication_deployments')
                || ! SchemaGuard::hasTable($db, 'reach_content_items')
            ) {
                return $this->ok([]);
            }

            // Avoid table aliases in Query Builder — some CI4/Postgres
            // identifier escaping paths quote "table alias" as one name.
            $rows = $db->table('reach_publication_deployments')
                ->select(
                    'reach_publication_deployments.id, '
                    . 'reach_publication_deployments.status, '
                    . 'reach_publication_deployments.scheduled_at, '
                    . 'reach_publication_deployments.content_item_id, '
                    . 'reach_content_items.title AS content_title, '
                    . 'reach_content_items.content_type'
                )
                ->join(
                    'reach_content_items',
                    'reach_content_items.id = reach_publication_deployments.content_item_id',
                    'left'
                )
                ->whereIn('reach_publication_deployments.status', ['scheduled', 'queued', 'published', 'verified'])
                ->orderBy('reach_publication_deployments.scheduled_at', 'ASC')
                ->limit(100)
                ->get()
                ->getResultArray();

            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'PublishingCalendarController::index: ' . $e->getMessage());
            // List endpoints must not hard-fail the Publishing UI.
            return $this->ok([]);
        }
    }
}

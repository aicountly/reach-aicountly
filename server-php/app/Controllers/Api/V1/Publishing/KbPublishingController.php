<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;

class KbPublishingController extends BaseApiController
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
            $select = 'reach_publication_deployments.*, reach_content_items.title AS content_title';
            $builder = $db->table('reach_publication_deployments')
                ->join(
                    'reach_content_items',
                    'reach_content_items.id = reach_publication_deployments.content_item_id',
                    'left'
                )
                ->where('reach_content_items.content_type', 'knowledge_base');

            if ($db->tableExists('reach_kb_publication_profiles')) {
                $select .= ', reach_kb_publication_profiles.article_type AS article_type';
                $builder->join(
                    'reach_kb_publication_profiles',
                    'reach_kb_publication_profiles.content_item_id = reach_publication_deployments.content_item_id',
                    'left'
                );
            }

            $rows = $builder
                ->select($select)
                ->orderBy('reach_publication_deployments.updated_at', 'DESC')
                ->limit(50)
                ->get()
                ->getResultArray();

            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'KbPublishingController::index: ' . $e->getMessage());
            // List endpoints must not hard-fail the Publishing UI.
            return $this->ok([]);
        }
    }
}

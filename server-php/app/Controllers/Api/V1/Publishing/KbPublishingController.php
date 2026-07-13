<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;

class KbPublishingController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = $this->db->table('reach_publication_deployments d')
            ->select('d.*, ci.title AS content_title, kp.article_type')
            ->join('reach_content_items ci', 'ci.id = d.content_item_id', 'left')
            ->join('reach_kb_publication_profiles kp', 'kp.content_item_id = d.content_item_id', 'left')
            ->where('ci.content_type', 'knowledge_base')
            ->orderBy('d.updated_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        return $this->ok($rows);
    }
}

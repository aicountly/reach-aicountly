<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;

class BlogPublishingController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = $this->db->table('reach_publication_deployments d')
            ->select('d.*, ci.title AS content_title')
            ->join('reach_content_items ci', 'ci.id = d.content_item_id', 'left')
            ->where('ci.content_type', 'blog')
            ->orderBy('d.updated_at', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        return $this->ok($rows);
    }
}

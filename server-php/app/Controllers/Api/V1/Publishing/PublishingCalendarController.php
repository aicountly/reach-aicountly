<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;

class PublishingCalendarController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = $this->db->table('reach_publication_deployments d')
            ->select('d.id, d.status, d.scheduled_at, d.content_item_id, ci.title AS content_title, ci.content_type')
            ->join('reach_content_items ci', 'ci.id = d.content_item_id', 'left')
            ->whereIn('d.status', ['scheduled', 'queued', 'published', 'verified'])
            ->orderBy('d.scheduled_at', 'ASC')
            ->limit(100)
            ->get()->getResultArray();

        return $this->ok($rows);
    }
}

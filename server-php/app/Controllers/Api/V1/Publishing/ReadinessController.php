<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Publishing\Seo\PublicationReadinessAggregator;

class ReadinessController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function evaluate(int $contentItemId): \CodeIgniter\HTTP\ResponseInterface
    {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)
            ->get()->getRowArray();

        if (!$item) {
            return $this->notFound('Content item not found');
        }

        $aggregator = new PublicationReadinessAggregator();
        $result     = $aggregator->evaluate($contentItemId, $item['content_type']);

        return $this->ok($result);
    }
}

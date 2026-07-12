<?php

namespace App\Controllers\Api\V1\Knowledge;

use App\Controllers\BaseApiController;
use App\Libraries\KnowledgeCompletenessService;

/**
 * Knowledge Completeness API
 *
 * GET /api/v1/knowledge/completeness              — summary for all products
 * GET /api/v1/knowledge/completeness/product/{id} — full detail for one product
 */
class CompletenessController extends BaseApiController
{
    private KnowledgeCompletenessService $service;

    public function __construct()
    {
        $this->service = new KnowledgeCompletenessService();
    }

    /**
     * GET /api/v1/knowledge/completeness
     *
     * Returns a summary completeness score for every product, sorted ascending
     * by score so the most incomplete products appear first.
     */
    public function index()
    {
        $summaries = $this->service->summaryAll();
        return $this->ok(['items' => $summaries, 'total' => count($summaries)]);
    }

    /**
     * GET /api/v1/knowledge/completeness/product/{id}
     *
     * Returns full completeness detail for a single product.
     */
    public function product(int $id)
    {
        $result = $this->service->forProduct($id);
        if (isset($result['error'])) {
            return $this->fail($result['error'], 404);
        }
        return $this->ok($result);
    }
}

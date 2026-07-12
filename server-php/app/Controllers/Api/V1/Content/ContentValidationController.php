<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\ContentValidationService;

/**
 * Validation results for content items.
 *
 * Routes:
 *   GET  /v1/content/items/:id/validations
 *   POST /v1/content/items/:id/validations
 *   POST /v1/content/items/:id/validations/:validationId/waive
 */
class ContentValidationController extends BaseContentController
{
    private ContentValidationService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new ContentValidationService();
    }

    public function index($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }
        return $this->ok(['validations' => $this->service->getResults($item['id'])]);
    }

    public function create($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body = $this->input();
        if (empty($body['validation_type']) || empty($body['validation_status'])) {
            return $this->fail('validation_type and validation_status are required.', 422);
        }

        try {
            $result = $this->service->storeResult(
                $item['id'],
                $body['validation_type'],
                $body['validation_status'],
                $body,
                $this->actor()
            );
            return $this->ok($result, 201);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function waive($id, $validationId)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body   = $this->input();
        $reason = trim($body['reason'] ?? '');

        try {
            $result = $this->service->waive((int) $validationId, $reason, $this->actor());
            return $this->ok($result);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }
}

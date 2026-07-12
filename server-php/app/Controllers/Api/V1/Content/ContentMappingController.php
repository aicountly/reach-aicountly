<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\ContentMappingService;

/**
 * Phase 1 knowledge entity mappings for content items.
 *
 * Routes:
 *   GET  /v1/content/items/:id/mappings
 *   PUT  /v1/content/items/:id/mappings         (sync all)
 *   POST /v1/content/items/:id/mappings/:type   (add single)
 *   DELETE /v1/content/items/:id/mappings/:type/:entityId
 */
class ContentMappingController extends BaseContentController
{
    private ContentMappingService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new ContentMappingService();
    }

    public function index($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }
        return $this->ok(['mappings' => $this->service->getMappings($item['id'])]);
    }

    public function sync($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body = $this->input();
        try {
            $this->service->sync($item['id'], $body, $this->actor());
            return $this->ok(['mappings' => $this->service->getMappings($item['id'])]);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function addMapping($id, $type)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body     = $this->input();
        $entityId = (int) ($body['entity_id'] ?? 0);
        if (!$entityId) {
            return $this->fail('entity_id is required.', 422);
        }

        try {
            $this->service->addMapping($item['id'], $type, $entityId, $this->actor());
            return $this->ok(['added' => true], 201);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function removeMapping($id, $type, $entityId)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $this->service->removeMapping($item['id'], $type, (int) $entityId);
        return $this->ok(['deleted' => true]);
    }
}

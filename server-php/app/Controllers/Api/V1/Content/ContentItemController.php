<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\ContentItemService;
use App\Libraries\ContentMappingService;
use App\Libraries\AuditLogger;

/**
 * CRUD + workflow endpoints for reach_content_items.
 *
 * Routes:
 *   GET    /v1/content/items
 *   POST   /v1/content/items
 *   GET    /v1/content/items/:id
 *   PUT    /v1/content/items/:id
 *   DELETE /v1/content/items/:id
 *   POST   /v1/content/items/:id/submit
 *   POST   /v1/content/items/:id/approve
 *   POST   /v1/content/items/:id/reject
 *   POST   /v1/content/items/:id/request-changes
 *   POST   /v1/content/items/:id/archive
 *   GET    /v1/content/items/:id/transitions
 */
class ContentItemController extends BaseContentController
{
    private ContentItemService   $service;
    private ContentMappingService $mapping;

    public function __construct()
    {
        parent::__construct();
        $this->service = new ContentItemService();
        $this->mapping = new ContentMappingService();
    }

    public function index()
    {
        $filters = [
            'content_type'    => $this->request->getGet('content_type'),
            'workflow_status' => $this->request->getGet('workflow_status'),
            'approval_status' => $this->request->getGet('approval_status'),
            'risk_level'      => $this->request->getGet('risk_level'),
            'primary_product_id' => $this->request->getGet('product_id'),
            'market_id'       => $this->request->getGet('market_id'),
            'search'          => $this->request->getGet('search'),
        ];
        $filters = array_filter($filters);

        [, $limit] = $this->pagination();
        $items = $this->contentItems->listPaged($filters, $limit);
        return $this->ok(['items' => $items, 'pager' => $this->contentItems->pager?->getDetails()]);
    }

    public function show($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $item['knowledge_maps'] = $this->mapping->getMappings($item['id']);
        return $this->ok($item);
    }

    public function create()
    {
        $body        = $this->input();
        $versionData = $body['version'] ?? [];
        unset($body['version']);

        if (!empty($body['body_html'])) {
            $body['body_html'] = $this->sanitizer->clean($body['body_html']);
        }
        if (!empty($versionData['body_html'])) {
            $versionData['body_html'] = $this->sanitizer->clean($versionData['body_html']);
        }

        try {
            $result = $this->service->create($body, $versionData, $this->actor());
            return $this->ok($result, 201);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function update($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body        = $this->input();
        $versionData = $body['version'] ?? [];
        unset($body['version']);

        if (!empty($versionData['body_html'])) {
            $versionData['body_html'] = $this->sanitizer->clean($versionData['body_html']);
        }

        try {
            $result = $this->service->update($item['id'], $body, $versionData, $this->actor());
            return $this->ok($result);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function delete($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body   = $this->input();
        $reason = trim($body['reason'] ?? '');

        try {
            $this->service->archive($item['id'], $reason ?: 'Deleted via API', $this->actor());
            return $this->ok(['deleted' => true]);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function submit($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        try {
            $updated = $this->workflow->submit($item['id'], $this->actor());
            return $this->ok($updated);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function approve($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body    = $this->input();
        $stage   = $body['stage'] ?? 'final_approval';
        $comment = $body['comment'] ?? '';

        try {
            $updated = $this->workflow->approve($item['id'], $stage, $this->actor(), $comment);
            return $this->ok($updated);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function reject($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body   = $this->input();
        $reason = trim($body['reason'] ?? '');
        $stage  = $body['stage'] ?? 'final_approval';

        try {
            $updated = $this->workflow->reject($item['id'], $stage, $this->actor(), $reason);
            return $this->ok($updated);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function requestChanges($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body   = $this->input();
        $reason = trim($body['reason'] ?? '');

        try {
            $updated = $this->workflow->requestChanges($item['id'], $this->actor(), $reason);
            return $this->ok($updated);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function archive($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body   = $this->input();
        $reason = trim($body['reason'] ?? '');

        try {
            $this->service->archive($item['id'], $reason, $this->actor());
            return $this->ok($this->contentItems->find($item['id']));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function transitions($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        return $this->ok([
            'current_status' => $item['workflow_status'],
            'next_statuses'  => $this->workflow->validNextStatuses($item['workflow_status']),
        ]);
    }
}

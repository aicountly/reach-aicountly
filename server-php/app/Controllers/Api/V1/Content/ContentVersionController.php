<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\ContentVersionService;

/**
 * Immutable version history for content items.
 *
 * Routes:
 *   GET  /v1/content/items/:id/versions
 *   GET  /v1/content/items/:id/versions/:versionId
 *   POST /v1/content/items/:id/versions
 *   GET  /v1/content/items/:id/versions/compare?a=:vId&b=:vId
 */
class ContentVersionController extends BaseContentController
{
    private ContentVersionService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new ContentVersionService();
    }

    public function index($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }
        return $this->ok(['versions' => $this->service->getHistory($item['id'])]);
    }

    public function show($id, $versionId)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }
        $version = $this->service->getVersion((int) $versionId);
        if (!$version || $version['content_item_id'] !== $item['id']) {
            return $this->fail('Version not found.', 404);
        }
        return $this->ok($version);
    }

    public function create($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body    = $this->input();
        $summary = $body['change_summary'] ?? '';
        unset($body['change_summary']);

        if (!empty($body['body_html'])) {
            $body['body_html'] = $this->sanitizer->clean($body['body_html']);
        }

        try {
            $version = $this->service->createVersion($item['id'], $body, $this->actor(), $summary);
            return $this->ok($version, 201);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function compare($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $a = (int) ($this->request->getGet('a') ?? 0);
        $b = (int) ($this->request->getGet('b') ?? 0);
        if (!$a || !$b) {
            return $this->fail('Both ?a and ?b version IDs are required.', 400);
        }

        try {
            return $this->ok($this->service->compare($a, $b));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }
}

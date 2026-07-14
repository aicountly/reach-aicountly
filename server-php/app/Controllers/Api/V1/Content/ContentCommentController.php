<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\ContentCommentService;

/**
 * Threaded comments on content items.
 *
 * Routes:
 *   GET    /v1/content/items/:id/comments
 *   POST   /v1/content/items/:id/comments
 *   POST   /v1/content/items/:id/comments/:commentId/resolve
 *   DELETE /v1/content/items/:id/comments/:commentId
 */
class ContentCommentController extends BaseContentController
{
    private ContentCommentService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new ContentCommentService();
    }

    public function index($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }
        $includeResolved = (bool) ($this->request->getGet('include_resolved') ?? false);
        return $this->ok(['comments' => $this->service->getThread($item['id'], $includeResolved)]);
    }

    public function create($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body = $this->input();
        $rawBody = $body['body'] ?? $body['body_html'] ?? null;
        if (empty($rawBody)) {
            return $this->fail('Comment body is required.', 422);
        }

        try {
            $comment = $this->service->addComment($item['id'], (string) $rawBody, $body, $this->actor());
            return $this->ok($comment, 201);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function resolve($id, $commentId)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        try {
            $comment = $this->service->resolve((int) $commentId, $this->actor());
            return $this->ok($comment);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function delete($id, $commentId)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $this->service->delete((int) $commentId, $this->actor());
        return $this->ok(['deleted' => true]);
    }
}

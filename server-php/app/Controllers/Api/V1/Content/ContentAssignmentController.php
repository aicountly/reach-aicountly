<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\ContentAssignmentService;

/**
 * Editorial role assignments on content items.
 *
 * Routes:
 *   GET    /v1/content/items/:id/assignments
 *   POST   /v1/content/items/:id/assignments
 *   DELETE /v1/content/items/:id/assignments/:assignmentId
 */
class ContentAssignmentController extends BaseContentController
{
    private ContentAssignmentService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new ContentAssignmentService();
    }

    public function index($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }
        return $this->ok(['assignments' => $this->service->getAssignments($item['id'])]);
    }

    public function create($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body = $this->input();
        if (empty($body['user_id']) || empty($body['role'])) {
            return $this->fail('user_id and role are required.', 422);
        }

        try {
            $assignment = $this->service->assign($item['id'], (int) $body['user_id'], $body['role'], $body, $this->actor());
            return $this->ok($assignment, 201);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function delete($id, $assignmentId)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        // Load assignment to get user_id/role for unassign
        $assignment = (new \App\Models\Content\ContentAssignmentModel())->find((int) $assignmentId);
        if (!$assignment || $assignment['content_item_id'] !== $item['id']) {
            return $this->fail('Assignment not found.', 404);
        }

        $this->service->unassign($item['id'], $assignment['user_id'], $assignment['role'], $this->actor());
        return $this->ok(['deleted' => true]);
    }
}

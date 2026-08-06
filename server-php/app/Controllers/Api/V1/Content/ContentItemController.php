<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\Blog\BlogHumanApprovalService;
use App\Libraries\Blog\BlogRedraftService;
use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\ContentItemService;
use App\Libraries\ContentMappingService;
use App\Libraries\ContentPurgeService;
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
 *   DELETE /v1/content/items/:id/purge
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
        $workflowStatus = $this->request->getGet('workflow_status');
        $filters = [
            'content_type'       => $this->request->getGet('content_type'),
            'approval_status'    => $this->request->getGet('approval_status'),
            'risk_level'         => $this->request->getGet('risk_level'),
            'primary_product_id' => $this->request->getGet('product_id'),
            'market_id'          => $this->request->getGet('market_id'),
            'search'             => $this->request->getGet('search'),
        ];

        // Support comma-separated statuses for BCC queues (e.g. internal_review,seo_review).
        if (is_string($workflowStatus) && str_contains($workflowStatus, ',')) {
            $filters['workflow_statuses'] = array_values(array_filter(array_map('trim', explode(',', $workflowStatus))));
        } elseif ($workflowStatus) {
            $filters['workflow_status'] = $workflowStatus;
        }

        $filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '' && $v !== []);

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

        $include = (string) ($this->request->getGet('include') ?? '');
        if ($include !== '' && str_contains($include, 'current_version')) {
            $db = \Config\Database::connect();
            $versionId = (int) ($item['current_version_id'] ?? 0);
            if ($versionId > 0) {
                $version = $db->table('reach_content_versions')
                    ->where('id', $versionId)
                    ->where('content_item_id', $item['id'])
                    ->get()
                    ->getRowArray();
                $item['current_version'] = $version ?: null;
            } else {
                $item['current_version'] = null;
            }

            $profile = $db->table('reach_blog_publication_profiles')
                ->where('content_item_id', $item['id'])
                ->get()
                ->getRowArray();
            $item['publication_profile'] = $profile ?: null;

            $media = $db->table('reach_content_media_requirements')
                ->where('content_item_id', $item['id'])
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
            $item['media_requirements'] = $media;
            $item['is_stub_body'] = (new BlogRedraftService())->itemHasStubBody($item);
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
            $body['body_html'] = $this->sanitizer->purify($body['body_html']);
        }
        if (!empty($versionData['body_html'])) {
            $versionData['body_html'] = $this->sanitizer->purify($versionData['body_html']);
        }

        try {
            $result = $this->service->create($body, $versionData, $this->actor());
            return $this->ok($result['item'], 201);
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
            $versionData['body_html'] = $this->sanitizer->purify($versionData['body_html']);
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

    /**
     * DELETE /v1/content/items/:id/purge
     *
     * Permanent removal, unlike delete() above which archives. Takes the item
     * down from the public site first; pass {"force": true} to delete from
     * Reach even when that takedown fails.
     */
    public function purge($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body   = $this->input();
        $reason = trim($body['reason'] ?? '');
        $force  = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $result = (new ContentPurgeService())->purge(
                (int) $item['id'],
                $reason ?: 'Permanently deleted from the Reach panel',
                $this->actor(),
                $force
            );
            return $this->ok($result);
        } catch (\RuntimeException $e) {
            // 409: the caller can retry with force once the public copy is handled.
            return $this->fail($e->getMessage(), 409);
        }
    }

    public function submit($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $actor = $this->actor();
        $blog  = new BlogHumanApprovalService();

        try {
            // Blogs run on BlogStateMachine, whose human-review state is
            // internal_review; the generic review_pending is not a blog state.
            if ($blog->isBlogSubmittable($item)) {
                $updated = $blog->submitForReview((int) $item['id'], (int) ($actor['id'] ?? 0));

                return $this->ok($updated);
            }

            $updated = $this->workflow->submit($item['id'], $actor);
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
        $actor   = $this->actor();
        $blog    = new BlogHumanApprovalService();

        try {
            if ($blog->isBlogAwaitingHuman($item)) {
                $updated = $blog->approve((int) $item['id'], (int) ($actor['id'] ?? 0), (string) $comment);

                return $this->ok($updated);
            }

            $updated = $this->workflow->approve($item['id'], $stage, $actor, $comment);

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
        $actor  = $this->actor();
        $blog   = new BlogHumanApprovalService();

        try {
            if ($blog->isBlogAwaitingHuman($item)) {
                $updated = $blog->reject((int) $item['id'], (int) ($actor['id'] ?? 0), $reason);

                return $this->ok($updated);
            }

            $updated = $this->workflow->reject($item['id'], $stage, $actor, $reason);

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
        $actor  = $this->actor();
        $blog   = new BlogHumanApprovalService();

        try {
            if ($blog->isBlogAwaitingHuman($item)) {
                $updated = $blog->requestChanges((int) $item['id'], (int) ($actor['id'] ?? 0), $reason);

                return $this->ok($updated);
            }

            $updated = $this->workflow->requestChanges($item['id'], $actor, $reason);

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

    /**
     * Re-queue AI draft generation when the current version is a stub/placeholder.
     */
    public function redraft($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        if (($item['content_type'] ?? '') !== 'blog') {
            return $this->fail('Redraft is only supported for blog content.', 422);
        }

        try {
            $result = (new BlogRedraftService())->redraft((int) $item['id'], (int) ($this->actor()['id'] ?? 0));
            $fresh  = $this->contentItems->find($item['id']);

            return $this->ok([
                'item'          => $fresh,
                'work_block_id' => $result['work_block_id'],
                'from_status'   => $result['from_status'],
                'message'       => 'Genuine draft generation queued. Run the blog worker (or wait for cron) to produce the full article.',
            ]);
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

        $current = (string) ($item['workflow_status'] ?? '');
        if (($item['content_type'] ?? '') === 'blog') {
            return $this->ok([
                'current_status' => $current,
                'next_statuses'  => (new BlogStateMachine())->allowedTargets($current),
            ]);
        }

        return $this->ok([
            'current_status' => $current,
            'next_statuses'  => $this->workflow->validNextStatuses($current),
        ]);
    }

    /** POST /v1/content/items/:id/transition — generic workflow transition */
    public function transition($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body   = $this->input();
        $status = $body['status'] ?? '';
        $reason = $body['reason'] ?? '';

        if (empty($status)) {
            return $this->fail('status is required.', 422);
        }

        return $this->transitionItem($item['id'], $status, (string) $reason);
    }
}

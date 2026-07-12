<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\ContentWorkflowService;
use App\Libraries\ContentValidationService;
use App\Models\Content\ContentItemModel;

/**
 * Daily Approval Centre API.
 *
 * Routes:
 *   GET  /v1/approval-queue                      — full cross-type queue
 *   GET  /v1/approval-queue/stats                — counts per area
 *   POST /v1/approval-queue/:id/approve
 *   POST /v1/approval-queue/:id/reject            — requires reason
 *   POST /v1/approval-queue/:id/return            — return for changes
 *   POST /v1/approval-queue/:id/waive-validation  — requires reason
 *   POST /v1/approval-queue/bulk-approve          — restricted (high/critical blocked)
 *
 * The 8 dashboard areas are computed from filters on workflow_status, risk_level,
 * and review_due_at.
 */
class ApprovalQueueController extends BaseContentController
{
    private ContentItemModel      $items;
    private ContentWorkflowService $workflow;
    private ContentValidationService $validations;

    /** Bulk-approve is blocked for these risk levels. */
    private const BULK_BLOCKED_RISK = ['high', 'critical'];

    public function __construct()
    {
        parent::__construct();
        $this->items       = new ContentItemModel();
        $this->workflow    = new ContentWorkflowService();
        $this->validations = new ContentValidationService();
    }

    public function index()
    {
        $area    = $this->request->getGet('area') ?? 'all';
        $type    = $this->request->getGet('content_type');
        $risk    = $this->request->getGet('risk_level');
        $status  = $this->request->getGet('workflow_status');

        $filters = $this->buildAreaFilters($area);
        if ($type)   $filters['content_type']    = $type;
        if ($risk)   $filters['risk_level']       = $risk;
        if ($status) $filters['workflow_status']  = $status;

        [, $limit] = $this->pagination(50);
        $items = $this->items->listPaged($filters, $limit);

        return $this->ok([
            'area'  => $area,
            'items' => $items,
            'pager' => $this->items->pager?->getDetails(),
        ]);
    }

    public function stats()
    {
        $areas = [
            'today'             => ['workflow_status' => 'review_pending'],
            'overdue'           => 'overdue',
            'high_risk'         => 'high_risk',
            'changes_requested' => ['workflow_status' => 'changes_requested'],
            'ready_for_approval'=> ['workflow_status' => 'review_pending'],
            'scheduled'         => ['workflow_status' => 'scheduled'],
            'recently_approved' => ['workflow_status' => 'approved'],
        ];

        $counts = [];
        foreach ($areas as $area => $filterDef) {
            $counts[$area] = $this->items->listPaged(
                is_string($filterDef) ? $this->buildAreaFilters($filterDef) : $filterDef,
                PHP_INT_MAX
            );
            $counts[$area] = count($counts[$area]);
        }

        return $this->ok(['counts' => $counts]);
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

    public function returnForChanges($id)
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

    public function waiveValidation($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body           = $this->input();
        $validationId   = (int) ($body['validation_id'] ?? 0);
        $reason         = trim($body['reason'] ?? '');

        if (!$validationId || !$reason) {
            return $this->fail('validation_id and reason are required.', 422);
        }

        try {
            $result = $this->validations->waive($validationId, $reason, $this->actor());
            return $this->ok($result);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /**
     * Bulk approve — restricted: high/critical risk items are blocked.
     */
    public function bulkApprove()
    {
        $body = $this->input();
        $ids  = array_map('intval', (array) ($body['ids'] ?? []));

        if (empty($ids)) {
            return $this->fail('ids array is required.', 422);
        }

        $results = ['approved' => [], 'blocked' => [], 'errors' => []];
        $stage   = $body['stage'] ?? 'final_approval';
        $comment = $body['comment'] ?? '';

        foreach ($ids as $id) {
            $item = $this->items->find($id);
            if (!$item) {
                $results['errors'][] = ['id' => $id, 'reason' => 'Not found'];
                continue;
            }

            if (in_array($item['risk_level'], self::BULK_BLOCKED_RISK, true)) {
                $results['blocked'][] = ['id' => $id, 'reason' => 'High/critical risk requires individual approval'];
                continue;
            }

            try {
                $this->workflow->approve($id, $stage, $this->actor(), $comment);
                $results['approved'][] = $id;
            } catch (\RuntimeException $e) {
                $results['errors'][] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return $this->ok($results);
    }

    /** Build filter arrays for each dashboard area. */
    private function buildAreaFilters(string $area): array
    {
        $now = date('Y-m-d H:i:s');
        return match ($area) {
            'today'              => ['workflow_status' => 'review_pending'],
            'overdue'            => ['overdue' => true],
            'high_risk'          => ['high_risk' => true],
            'changes_requested'  => ['workflow_status' => 'changes_requested'],
            'ready_for_approval' => ['workflow_status' => 'review_pending'],
            'scheduled'          => ['workflow_status' => 'scheduled'],
            'recently_approved'  => ['workflow_status' => 'approved'],
            default              => ['workflow_status' => 'review_pending'],
        };
    }
}

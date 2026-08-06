<?php

namespace App\Libraries\Blog;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Human resolve path for blog automation items parked in INTERNAL_REVIEW
 * (or still in SEO_REVIEW). Uses BlogStateMachine + checksum-bound
 * BlogContentApprovalService — not the generic ContentWorkflowService stage queue.
 */
class BlogHumanApprovalService
{
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** Blog states a human may push into internal_review from. */
    private const SUBMITTABLE = [
        BlogStateMachine::DRAFT,
        BlogStateMachine::FACT_VERIFIED,
        BlogStateMachine::CHANGES_REQUESTED,
    ];

    /**
     * Hand a blog item to human review (internal_review).
     *
     * Blog items must not go through ContentWorkflowService's review_pending —
     * that state is absent from BlogStateMachine, so an item parked there is
     * invisible to isBlogAwaitingHuman() and to the HUMAN_REVIEW_GATE work
     * block, leaving it unapprovable.
     *
     * @return array<string,mixed> Updated content item row
     */
    public function submitForReview(int $contentItemId, int $actorId, string $reason = ''): array
    {
        $item   = $this->requireBlogItem($contentItemId);
        $status = strtolower((string) ($item['workflow_status'] ?? ''));

        if (! in_array($status, self::SUBMITTABLE, true)) {
            throw new \RuntimeException(
                "Blog item must be in draft, fact_verified or changes_requested to submit for review (current: {$status})."
            );
        }

        (new BlogStateMachine())->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, $actorId, [
            'reason' => $reason !== '' ? $reason : 'submitted_for_human_review',
        ]);

        return $this->requireBlogItem($contentItemId);
    }

    /**
     * True when the blog submit path applies — used to route the generic
     * submit endpoint away from ContentWorkflowService.
     *
     * @param array<string,mixed> $item
     */
    public function isBlogSubmittable(array $item): bool
    {
        if (($item['content_type'] ?? '') !== 'blog') {
            return false;
        }

        return in_array(strtolower((string) ($item['workflow_status'] ?? '')), self::SUBMITTABLE, true);
    }

    /**
     * @return array<string,mixed> Updated content item row
     */
    public function approve(int $contentItemId, int $approverId, string $reason = ''): array
    {
        $item = $this->requireBlogItem($contentItemId);
        $status = strtolower((string) ($item['workflow_status'] ?? ''));

        if (! in_array($status, [BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::SEO_REVIEW], true)) {
            throw new \RuntimeException(
                "Blog item must be in internal_review or seo_review to approve (current: {$status})."
            );
        }

        $versionId = (int) ($item['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new \RuntimeException('Blog item has no current_version_id to approve.');
        }

        $sm = new BlogStateMachine();
        if ($status === BlogStateMachine::SEO_REVIEW) {
            $sm->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, $approverId, [
                'reason' => 'human_approval_from_seo_review',
            ]);
        }
        $sm->transition($contentItemId, BlogStateMachine::APPROVED, $approverId, [
            'reason' => $reason !== '' ? $reason : 'human_approved',
        ]);

        (new BlogContentApprovalService())->approve(
            $contentItemId,
            $versionId,
            $approverId,
            'standard',
            $reason !== '' ? $reason : 'Human approved via Content Studio / Approval Centre',
        );

        $this->completeHumanReviewGates($contentItemId, [
            'outcome'   => 'approved',
            'version_id'=> $versionId,
            'approver'  => $approverId,
        ]);

        return $this->requireBlogItem($contentItemId);
    }

    /**
     * @return array<string,mixed>
     */
    public function reject(int $contentItemId, int $actorId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('Rejection reason is required.');
        }

        $item = $this->requireBlogItem($contentItemId);
        $status = strtolower((string) ($item['workflow_status'] ?? ''));

        if (! in_array($status, [BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::SEO_REVIEW], true)) {
            throw new \RuntimeException(
                "Blog item must be in internal_review or seo_review to reject (current: {$status})."
            );
        }

        (new BlogStateMachine())->transition($contentItemId, BlogStateMachine::REJECTED, $actorId, [
            'reason' => $reason,
        ]);

        $this->db->table('reach_content_items')->where('id', $contentItemId)->update([
            'approval_status' => 'rejected',
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->completeHumanReviewGates($contentItemId, [
            'outcome' => 'rejected',
            'reason'  => $reason,
            'actor'   => $actorId,
        ]);

        return $this->requireBlogItem($contentItemId);
    }

    /**
     * @return array<string,mixed>
     */
    public function requestChanges(int $contentItemId, int $actorId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('A reason is required when requesting changes.');
        }

        $item = $this->requireBlogItem($contentItemId);
        $status = strtolower((string) ($item['workflow_status'] ?? ''));

        if (! in_array($status, [BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::SEO_REVIEW], true)) {
            throw new \RuntimeException(
                "Blog item must be in internal_review or seo_review to request changes (current: {$status})."
            );
        }

        (new BlogStateMachine())->transition($contentItemId, BlogStateMachine::CHANGES_REQUESTED, $actorId, [
            'reason' => $reason,
        ]);

        $this->completeHumanReviewGates($contentItemId, [
            'outcome' => 'changes_requested',
            'reason'  => $reason,
            'actor'   => $actorId,
        ]);

        return $this->requireBlogItem($contentItemId);
    }

    public function isBlogAwaitingHuman(array $item): bool
    {
        if (($item['content_type'] ?? '') !== 'blog') {
            return false;
        }

        return in_array(
            strtolower((string) ($item['workflow_status'] ?? '')),
            [BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::SEO_REVIEW],
            true
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function requireBlogItem(int $contentItemId): array
    {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)
            ->where('content_type', 'blog')
            ->get()
            ->getRowArray();

        if (! $item) {
            throw new \RuntimeException("Blog content item {$contentItemId} not found.");
        }

        return $item;
    }

    /**
     * @param array<string,mixed> $output
     */
    private function completeHumanReviewGates(int $contentItemId, array $output): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_work_blocks')
            ->where('content_item_id', $contentItemId)
            ->where('block_type', WorkBlockService::TYPE_HUMAN_REVIEW_GATE)
            ->whereIn('eligibility_status', ['blocked', 'eligible', 'leased', 'running'])
            ->update([
                'eligibility_status' => 'completed',
                'output_json'        => json_encode($output, JSON_UNESCAPED_SLASHES),
                'completed_at'       => $now,
                'updated_at'         => $now,
            ]);
    }
}

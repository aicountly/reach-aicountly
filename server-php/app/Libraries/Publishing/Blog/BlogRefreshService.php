<?php

namespace App\Libraries\Publishing\Blog;

use App\Libraries\AuditLogger;

/**
 * Phase 4 â€” Blog content refresh lifecycle management.
 *
 * Manages when published blogs need review or refresh.
 * Refresh triggers are recorded in reach_publication_refresh_reviews.
 */
class BlogRefreshService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Request a refresh for a published blog.
     */
    public function requestRefresh(
        int $contentItemId,
        string $triggerType,
        string $triggerDetail = '',
        ?int $requestedBy = null,
        ?\DateTimeInterface $dueAt = null
    ): int {
        $allowed = [
            'review_date_reached','source_expired','citation_invalidated',
            'product_claim_changed','feature_availability_changed','broken_link',
            'major_product_release','manual_request',
        ];

        if (!in_array($triggerType, $allowed, true)) {
            throw new \InvalidArgumentException("Unknown trigger type: {$triggerType}");
        }

        $this->db->table('reach_publication_refresh_reviews')->insert([
            'content_item_id' => $contentItemId,
            'trigger_type'    => $triggerType,
            'trigger_detail'  => $triggerDetail,
            'status'          => 'pending',
            'requested_by'    => $requestedBy,
            'due_at'          => $dueAt?->format('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $reviewId = $this->db->insertID();

        // Update blog profile refresh status
        $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->update(['refresh_status' => 'refresh_due', 'updated_at' => date('Y-m-d H:i:s')]);

        AuditLogger::record('publishing.refresh_requested', [
            'content_item_id' => $contentItemId,
            'trigger_type'    => $triggerType,
            'review_id'       => $reviewId,
        ], $requestedBy);

        return $reviewId;
    }

    /**
     * Mark a refresh review as complete.
     */
    public function completeRefresh(int $reviewId, ?int $actorId = null): void
    {
        $this->db->table('reach_publication_refresh_reviews')
            ->where('id', $reviewId)
            ->update([
                'status'       => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

        $review = $this->db->table('reach_publication_refresh_reviews')
            ->where('id', $reviewId)->get()->getRowArray();

        if ($review) {
            $this->db->table('reach_blog_publication_profiles')
                ->where('content_item_id', $review['content_item_id'])
                ->update(['refresh_status' => 'published', 'last_refreshed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * Find blogs due for refresh.
     */
    public function findDueForRefresh(): array
    {
        return $this->db->table('reach_publication_refresh_reviews')
            ->where('status', 'pending')
            ->where('due_at <=', date('Y-m-d H:i:s'))
            ->get()->getResultArray();
    }

    /**
     * Phase 9: wire a Phase 9 refresh workflow publication to the blog refresh pipeline.
     * Records the refresh review and links it to the workflow publication idempotency key.
     */
    public function publishFromWorkflow(
        int    $contentItemId,
        int    $workflowId,
        string $idempotencyKey,
        ?int   $actorId = null,
    ): int {
        $reviewId = $this->requestRefresh(
            $contentItemId,
            'manual_request',
            "Phase 9 refresh workflow {$workflowId}",
            $actorId,
        );
        AuditLogger::record('refresh.published', [
            'content_item_id' => $contentItemId,
            'workflow_id'     => $workflowId,
            'idempotency_key' => $idempotencyKey,
            'review_id'       => $reviewId,
        ], $actorId);
        return $reviewId;
    }
}

<?php

namespace App\Libraries\Publishing\KnowledgeBase;

use App\Libraries\AuditLogger;

/**
 * Phase 4 — Knowledge-base content refresh lifecycle.
 */
class KnowledgeBaseRefreshService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

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

        $this->db->table('reach_kb_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->update(['refresh_status' => 'refresh_due', 'updated_at' => date('Y-m-d H:i:s')]);

        AuditLogger::log('publishing.refresh_requested', [
            'content_item_id' => $contentItemId,
            'content_type'    => 'knowledge_base',
            'trigger_type'    => $triggerType,
            'review_id'       => $reviewId,
        ], $requestedBy);

        return $reviewId;
    }
}

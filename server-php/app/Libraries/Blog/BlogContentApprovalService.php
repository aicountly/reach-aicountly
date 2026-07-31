<?php

namespace App\Libraries\Blog;

use App\Libraries\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Checksum-bound, version-specific human approval for blog content.
 *
 * Modeled directly on App\Libraries\Community\OfficialAnswerApprovalService.
 * Every approval is locked to one content_version_id and its exact content
 * checksum. A new content version never inherits a prior approval — this is
 * how "amending content invalidates prior approval" is enforced structurally
 * rather than by a separate invalidation step. Revoking an approval blocks
 * publication immediately regardless of workflow_status.
 */
class BlogContentApprovalService
{
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Compute the canonical checksum for a content version's approvable
     * material (title + body). Any change to either invalidates approval.
     */
    public function checksumForVersion(int $contentVersionId): string
    {
        $version = $this->db->table('reach_content_versions')
            ->where('id', $contentVersionId)
            ->get()
            ->getRowArray();

        if (! $version) {
            throw new \RuntimeException("Content version {$contentVersionId} not found");
        }

        $material = [
            'title'      => (string) ($version['title'] ?? ''),
            'body_html'  => (string) ($version['body_html'] ?? ''),
            'body_md'    => (string) ($version['body_markdown'] ?? ''),
        ];

        return hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Approve an exact content item + version. The checksum is captured at
     * approval time and re-verified before every publication attempt.
     */
    public function approve(
        int $contentItemId,
        int $contentVersionId,
        int $approverId,
        string $approvalType = 'standard',
        string $reason = '',
    ): array {
        $version = $this->db->table('reach_content_versions')
            ->where('id', $contentVersionId)
            ->where('content_item_id', $contentItemId)
            ->get()
            ->getRowArray();

        if (! $version) {
            throw new \RuntimeException("Content version {$contentVersionId} does not belong to item {$contentItemId}");
        }

        $checksum = $this->checksumForVersion($contentVersionId);
        $now      = date('Y-m-d H:i:s');

        // Revoke any prior active approval for this content item before recording the new one.
        $this->db->table('reach_blog_content_approvals')
            ->where('content_item_id', $contentItemId)
            ->where('outcome', 'approved')
            ->where('revoked_at IS NULL', null, false)
            ->update(['revoked_at' => $now, 'revoke_reason' => 'superseded_by_new_approval', 'updated_at' => $now]);

        $this->db->table('reach_blog_content_approvals')->insert([
            'content_item_id'    => $contentItemId,
            'content_version_id' => $contentVersionId,
            'version_checksum'   => $checksum,
            'approval_type'      => $approvalType,
            'outcome'            => 'approved',
            'approved_by'        => $approverId,
            'reason'             => $reason,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $approvalId = (int) $this->db->insertID();

        $this->db->table('reach_content_items')->where('id', $contentItemId)->update([
            'approval_status' => 'approved',
            'approved_at'      => $now,
            'approved_by'      => $approverId,
            'updated_at'       => $now,
        ]);

        AuditLogger::record('blog.content_approved', [
            'content_item_id'    => $contentItemId,
            'content_version_id' => $contentVersionId,
            'approval_id'        => $approvalId,
            'checksum_prefix'    => substr($checksum, 0, 8),
        ], $approverId);

        return [
            'approval_id' => $approvalId,
            'checksum'    => $checksum,
        ];
    }

    /**
     * Revoke the active approval for a content item. Blocks publication
     * immediately — verifyForPublication() will return false afterwards.
     */
    public function revoke(int $contentItemId, string $reason, ?int $actorId = null): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Revoke reason is required.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_blog_content_approvals')
            ->where('content_item_id', $contentItemId)
            ->where('outcome', 'approved')
            ->where('revoked_at IS NULL', null, false)
            ->update([
                'revoked_at'    => $now,
                'revoked_by'    => $actorId,
                'revoke_reason' => $reason,
                'updated_at'    => $now,
            ]);

        $this->db->table('reach_content_items')->where('id', $contentItemId)->update([
            'approval_status' => 'rejected',
            'updated_at'      => $now,
        ]);

        AuditLogger::record('blog.content_approval_revoked', [
            'content_item_id' => $contentItemId,
            'reason'           => substr($reason, 0, 200),
        ], $actorId);
    }

    /**
     * Last-line publication gate: the exact version being published must
     * carry a valid, non-revoked approval whose checksum matches the
     * version's current content. Returns false on any mismatch.
     */
    public function verifyForPublication(int $contentItemId, int $contentVersionId): bool
    {
        $approval = $this->activeApproval($contentItemId, $contentVersionId);
        if ($approval === null) {
            return false;
        }

        $currentChecksum = $this->checksumForVersion($contentVersionId);
        $valid = hash_equals((string) $approval['version_checksum'], $currentChecksum);

        if (! $valid) {
            AuditLogger::record('blog.content_approval_checksum_mismatch', [
                'content_item_id'    => $contentItemId,
                'content_version_id' => $contentVersionId,
            ]);
        }

        return $valid;
    }

    /**
     * Returns the protected reviewer's display name ONLY when:
     *  - a valid, non-revoked, checksum-matching approval exists for this
     *    exact version, AND
     *  - the approver is registered in reach_blog_protected_reviewers and
     *    active.
     *
     * Free-text reviewer_reference fields elsewhere can never produce a
     * non-null result here.
     */
    public function protectedReviewerName(int $contentItemId, int $contentVersionId): ?string
    {
        if (! $this->verifyForPublication($contentItemId, $contentVersionId)) {
            return null;
        }

        $approval = $this->activeApproval($contentItemId, $contentVersionId);
        if ($approval === null || empty($approval['approved_by'])) {
            return null;
        }

        $reviewer = $this->db->table('reach_blog_protected_reviewers')
            ->where('user_id', $approval['approved_by'])
            ->where('active', true)
            ->get()
            ->getRowArray();

        return $reviewer ? (string) $reviewer['display_name'] : null;
    }

    private function activeApproval(int $contentItemId, int $contentVersionId): ?array
    {
        return $this->db->table('reach_blog_content_approvals')
            ->where('content_item_id', $contentItemId)
            ->where('content_version_id', $contentVersionId)
            ->where('outcome', 'approved')
            ->where('revoked_at IS NULL', null, false)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray() ?: null;
    }
}

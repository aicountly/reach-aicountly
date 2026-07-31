<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Checksum-bound, version-specific human approval for blog content.
 *
 * Mirrors the Community official-answer approval pattern
 * (App\Libraries\Community\OfficialAnswerApprovalService): approval is locked
 * to an exact content_version_id + content checksum. A new version never
 * inherits a prior approval. Revocation blocks publication immediately.
 */
class CreateReachBlogContentApprovals extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_blog_content_approvals (
                id                   BIGSERIAL PRIMARY KEY,
                content_item_id      BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                content_version_id   BIGINT NOT NULL REFERENCES reach_content_versions(id) ON DELETE CASCADE,
                version_checksum     VARCHAR(64) NOT NULL,
                approval_type        VARCHAR(32) NOT NULL DEFAULT 'standard'
                                     CHECK (approval_type IN ('standard','professional_review','compliance_review')),
                outcome              VARCHAR(16) NOT NULL DEFAULT 'approved'
                                     CHECK (outcome IN ('approved','rejected','revoked')),
                approved_by          BIGINT REFERENCES reach_actors(id) ON DELETE SET NULL,
                reason               TEXT,
                revoked_at           TIMESTAMPTZ,
                revoked_by           BIGINT REFERENCES reach_actors(id) ON DELETE SET NULL,
                revoke_reason        TEXT,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_blog_approvals_item ON reach_blog_content_approvals(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_blog_approvals_version ON reach_blog_content_approvals(content_version_id)');
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS uq_blog_approvals_active_version ON reach_blog_content_approvals(content_version_id) WHERE outcome = \'approved\' AND revoked_at IS NULL');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_blog_content_approvals CASCADE');
    }
}

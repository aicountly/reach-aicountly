<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registry of named reviewers whose credentialed attribution (e.g.
 * "Reviewed by CA Rahul Gupta") may legitimately appear on published content.
 *
 * A display name may only be attached to a publication when:
 *  - the approver_id on the checksum-bound approval matches user_id here, AND
 *  - the approval is valid for the exact published version (see
 *    BlogContentApprovalService::protectedReviewerName()).
 *
 * Free-text reviewer fields elsewhere in the schema (e.g.
 * reach_blog_publication_profiles.reviewer_reference) can never produce this
 * attribution on their own.
 */
class CreateReachBlogProtectedReviewers extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_blog_protected_reviewers (
                id              BIGSERIAL PRIMARY KEY,
                user_id         BIGINT NOT NULL UNIQUE REFERENCES reach_actors(id) ON DELETE CASCADE,
                display_name    VARCHAR(255) NOT NULL,
                credential_type VARCHAR(64),
                active          BOOLEAN NOT NULL DEFAULT TRUE,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_blog_protected_reviewers_active ON reach_blog_protected_reviewers(active)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_blog_protected_reviewers CASCADE');
    }
}

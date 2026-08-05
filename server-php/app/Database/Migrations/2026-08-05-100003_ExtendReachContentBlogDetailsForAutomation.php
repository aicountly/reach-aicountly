<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Blog automation phase 2:
 * - fact_revision_attempts / last_verification_json power the bounded
 *   Perplexity-driven REVISE_CONTENT loop (verifier stays a pure judge; the
 *   generating provider applies its findings).
 * - origin distinguishes pipeline-generated items ('pipeline') from drafts
 *   ingested pre-verified from the Claude routine ('claude_routine'), which
 *   use gallery covers instead of AI image generation.
 */
class ExtendReachContentBlogDetailsForAutomation extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE reach_content_blog_details ADD COLUMN IF NOT EXISTS fact_revision_attempts INT NOT NULL DEFAULT 0");
        $this->db->query("ALTER TABLE reach_content_blog_details ADD COLUMN IF NOT EXISTS last_verification_json JSONB");
        $this->db->query("ALTER TABLE reach_content_blog_details ADD COLUMN IF NOT EXISTS origin VARCHAR(32) NOT NULL DEFAULT 'pipeline'");
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_blog_details_origin ON reach_content_blog_details(origin)");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE reach_content_blog_details DROP COLUMN IF EXISTS fact_revision_attempts');
        $this->db->query('ALTER TABLE reach_content_blog_details DROP COLUMN IF EXISTS last_verification_json');
        $this->db->query('ALTER TABLE reach_content_blog_details DROP COLUMN IF EXISTS origin');
    }
}

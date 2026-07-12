<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 2 — Bridge blog posts to unified content items.
 *
 * Adds a nullable content_item_id FK to reach_blog_posts for backward-compatible
 * bridging. Existing blog posts are unaffected (field remains NULL).
 * New blog content created via the Content Studio will set this field.
 */
class ExtendBlogPostsForContentItems extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE reach_blog_posts "
            . "ADD COLUMN IF NOT EXISTS content_item_id BIGINT NULL "
            . "REFERENCES reach_content_items(id) ON DELETE SET NULL"
        );
        $this->db->query(
            "CREATE INDEX IF NOT EXISTS rbp_content_item_id_idx "
            . "ON reach_blog_posts (content_item_id) WHERE content_item_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        $this->db->query("DROP INDEX IF EXISTS rbp_content_item_id_idx");
        $this->db->query("ALTER TABLE reach_blog_posts DROP COLUMN IF EXISTS content_item_id");
    }
}

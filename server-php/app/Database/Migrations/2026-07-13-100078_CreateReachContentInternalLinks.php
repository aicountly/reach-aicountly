<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentInternalLinks extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_content_internal_links (
                id                          BIGSERIAL PRIMARY KEY,
                source_content_item_id      BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                source_content_version_id   BIGINT REFERENCES reach_content_versions(id) ON DELETE SET NULL,
                target_type                 VARCHAR(32) NOT NULL DEFAULT 'internal'
                                            CHECK (target_type IN ('internal','external','anchor')),
                target_content_item_id      BIGINT REFERENCES reach_content_items(id) ON DELETE SET NULL,
                target_public_url           VARCHAR(2048),
                anchor_text                 VARCHAR(512),
                context_text                TEXT,
                link_reason                 VARCHAR(255),
                status                      VARCHAR(32) NOT NULL DEFAULT 'draft'
                                            CHECK (status IN ('draft','active','broken','removed')),
                validation_status           VARCHAR(32) NOT NULL DEFAULT 'pending'
                                            CHECK (validation_status IN ('pending','valid','invalid','skipped')),
                last_checked_at             TIMESTAMPTZ,
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_internal_links_source ON reach_content_internal_links(source_content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_internal_links_target ON reach_content_internal_links(target_content_item_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_content_internal_links CASCADE');
    }
}

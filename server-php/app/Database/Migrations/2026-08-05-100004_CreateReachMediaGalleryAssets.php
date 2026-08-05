<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Media asset store shared by AI-generated blog covers ('ai_generated') and
 * the human-curated cover gallery for routine blogs ('gallery_upload').
 * Binary files live under writable/uploads/media/ (rsync-protected); rows
 * carry checksums for dedupe, usage counters for rotation, and the prompt
 * used so superadmins can regenerate similar covers externally.
 */
class CreateReachMediaGalleryAssets extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_media_gallery_assets (
                id                 BIGSERIAL PRIMARY KEY,
                asset_uuid         VARCHAR(64) NOT NULL UNIQUE,
                kind               VARCHAR(24) NOT NULL DEFAULT 'gallery_upload'
                                   CHECK (kind IN ('gallery_upload','ai_generated','worker_screenshot')),
                content_item_id    BIGINT REFERENCES reach_content_items(id) ON DELETE SET NULL,
                file_path          VARCHAR(500) NOT NULL,
                mime               VARCHAR(64) NOT NULL DEFAULT 'image/webp',
                bytes              INT NOT NULL DEFAULT 0,
                width              INT,
                height             INT,
                checksum_sha256    VARCHAR(64) NOT NULL UNIQUE,
                prompt_used        TEXT,
                category_tags      JSONB NOT NULL DEFAULT '[]'::jsonb,
                portfolio_stream   VARCHAR(32),
                status             VARCHAR(16) NOT NULL DEFAULT 'active'
                                   CHECK (status IN ('active','retired')),
                times_used         INT NOT NULL DEFAULT 0,
                last_used_at       TIMESTAMPTZ,
                created_by         BIGINT,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_media_gallery_status ON reach_media_gallery_assets(status, kind)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_media_gallery_stream ON reach_media_gallery_assets(portfolio_stream)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_media_gallery_rotation ON reach_media_gallery_assets(times_used, last_used_at)');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_media_gallery_usages (
                id              BIGSERIAL PRIMARY KEY,
                asset_id        BIGINT NOT NULL REFERENCES reach_media_gallery_assets(id) ON DELETE CASCADE,
                content_item_id BIGINT REFERENCES reach_content_items(id) ON DELETE SET NULL,
                used_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_media_gallery_usages_asset ON reach_media_gallery_usages(asset_id)');
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_media_gallery_usages', true);
        $this->forge->dropTable('reach_media_gallery_assets', true);
    }
}

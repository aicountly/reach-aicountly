<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Covers without a human (stream 1, 18 Oct 2026).
 *
 * reach_media_gallery_assets
 *   - kind `ai_gallery`: a cover Reach generated itself (GPT Image) and keeps
 *     in the Cover Gallery for reuse — stocked ahead of the blog index by the
 *     hourly autofill job, or generated for an article no gallery cover fitted.
 *   - source_key: the content-base entry (reach_content_items.content_base_key)
 *     an auto-stocked cover was generated for. Unique per `ai_gallery` row, so
 *     the autofill is idempotent per entry and never pays for the same cover twice.
 *   - provenance: `aicountly_generated` (Reach drew it from the article's own
 *     topic) or `operator_upload`. NULL = unknown origin.
 *   - branded: the AICOUNTLY logo is composited onto the stored file.
 *   - original_file_path: the unbranded original, kept beside the branded file.
 *   - model_key: the image model that produced it.
 *
 * reach_cover_generation_attempts
 *   One row per paid generation attempt (success or failure). The daily cap
 *   (covers.auto.daily_limit), the daily report's "Covers" line and the
 *   repeated-failure alert all read it, so none of them guesses.
 */
class AutoCoverGallery extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_media_gallery_assets DROP CONSTRAINT IF EXISTS reach_media_gallery_assets_kind_check');
        $this->db->query("ALTER TABLE reach_media_gallery_assets ADD CONSTRAINT reach_media_gallery_assets_kind_check
            CHECK (kind IN ('gallery_upload','ai_generated','worker_screenshot','ai_gallery'))");

        $this->db->query('ALTER TABLE reach_media_gallery_assets ADD COLUMN IF NOT EXISTS source_key VARCHAR(191) NULL');
        $this->db->query('ALTER TABLE reach_media_gallery_assets ADD COLUMN IF NOT EXISTS provenance VARCHAR(32) NULL');
        $this->db->query('ALTER TABLE reach_media_gallery_assets ADD COLUMN IF NOT EXISTS branded BOOLEAN NOT NULL DEFAULT FALSE');
        $this->db->query('ALTER TABLE reach_media_gallery_assets ADD COLUMN IF NOT EXISTS original_file_path VARCHAR(500) NULL');
        $this->db->query('ALTER TABLE reach_media_gallery_assets ADD COLUMN IF NOT EXISTS model_key VARCHAR(64) NULL');
        $this->db->query("CREATE UNIQUE INDEX IF NOT EXISTS uq_media_gallery_ai_source_key
            ON reach_media_gallery_assets(source_key) WHERE source_key IS NOT NULL AND kind = 'ai_gallery'");

        // Existing rows: what Reach generated vs what an operator uploaded.
        // Neither is branded yet, so reach:covers-refresh treats both as
        // candidates for a branded, topic-drawn replacement.
        $this->db->query("UPDATE reach_media_gallery_assets SET provenance = 'aicountly_generated' WHERE provenance IS NULL AND kind = 'ai_generated'");
        $this->db->query("UPDATE reach_media_gallery_assets SET provenance = 'operator_upload' WHERE provenance IS NULL AND kind = 'gallery_upload'");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_cover_generation_attempts (
                id              BIGSERIAL PRIMARY KEY,
                purpose         VARCHAR(24) NOT NULL CHECK (purpose IN ('gallery_stock','article','refresh')),
                status          VARCHAR(16) NOT NULL CHECK (status IN ('generated','failed')),
                source_key      VARCHAR(191) NULL,
                content_item_id BIGINT NULL REFERENCES reach_content_items(id) ON DELETE SET NULL,
                asset_id        BIGINT NULL REFERENCES reach_media_gallery_assets(id) ON DELETE SET NULL,
                model_key       VARCHAR(64) NULL,
                error           TEXT NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_cover_attempts_created ON reach_cover_generation_attempts(created_at, status)');
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_cover_generation_attempts', true);
        $this->db->query('DROP INDEX IF EXISTS uq_media_gallery_ai_source_key');
        foreach (['model_key', 'original_file_path', 'branded', 'provenance', 'source_key'] as $column) {
            $this->db->query("ALTER TABLE reach_media_gallery_assets DROP COLUMN IF EXISTS {$column}");
        }
        $this->db->query("DELETE FROM reach_media_gallery_assets WHERE kind = 'ai_gallery'");
        $this->db->query('ALTER TABLE reach_media_gallery_assets DROP CONSTRAINT IF EXISTS reach_media_gallery_assets_kind_check');
        $this->db->query("ALTER TABLE reach_media_gallery_assets ADD CONSTRAINT reach_media_gallery_assets_kind_check
            CHECK (kind IN ('gallery_upload','ai_generated','worker_screenshot'))");
    }
}

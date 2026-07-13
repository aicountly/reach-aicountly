<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentMediaRequirements extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_content_media_requirements (
                id                  BIGSERIAL PRIMARY KEY,
                content_item_id     BIGINT NOT NULL REFERENCES reach_content_items(id) ON DELETE CASCADE,
                content_version_id  BIGINT REFERENCES reach_content_versions(id) ON DELETE SET NULL,
                media_type          VARCHAR(32) NOT NULL
                                    CHECK (media_type IN ('featured_image','inline_image','screenshot','diagram','video','download')),
                placement           VARCHAR(128),
                purpose             VARCHAR(255),
                brief               TEXT,
                alt_text            VARCHAR(512),
                caption             VARCHAR(512),
                required_width      INTEGER,
                required_height     INTEGER,
                aspect_ratio        VARCHAR(16),
                status              VARCHAR(32) NOT NULL DEFAULT 'required'
                                    CHECK (status IN ('required','provided','waived','not_applicable')),
                asset_reference     VARCHAR(512),
                public_url          VARCHAR(2048),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_media_req_item ON reach_content_media_requirements(content_item_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_media_req_status ON reach_content_media_requirements(status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_content_media_requirements CASCADE');
    }
}

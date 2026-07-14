<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoChapterMarkers extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_chapter_markers (
                id                  BIGSERIAL    PRIMARY KEY,
                uuid                UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                script_version_id   BIGINT       NOT NULL REFERENCES reach_video_script_versions(id) ON DELETE CASCADE,
                chapter_order       INT          NOT NULL DEFAULT 0,
                title               VARCHAR(500) NOT NULL,
                start_time_secs     INT          NOT NULL DEFAULT 0,
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvcm_version  ON reach_video_chapter_markers(script_version_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvcm_order    ON reach_video_chapter_markers(script_version_id, chapter_order)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_chapter_markers CASCADE');
    }
}

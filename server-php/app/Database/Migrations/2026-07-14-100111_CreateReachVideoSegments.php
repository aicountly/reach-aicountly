<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoSegments extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_segments (
                id                  BIGSERIAL    PRIMARY KEY,
                uuid                UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                script_version_id   BIGINT       NOT NULL REFERENCES reach_video_script_versions(id) ON DELETE CASCADE,
                segment_order       INT          NOT NULL DEFAULT 0,
                segment_type        VARCHAR(64)  NOT NULL DEFAULT 'scene'
                                    CHECK (segment_type IN ('hook','intro','scene','transition','cta','outro')),
                title               VARCHAR(255),
                voice_over_text     TEXT,
                visual_direction    TEXT,
                duration_hint_secs  INT,
                metadata            JSONB,
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvsg_version  ON reach_video_segments(script_version_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvsg_order    ON reach_video_segments(script_version_id, segment_order)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_segments CASCADE');
    }
}

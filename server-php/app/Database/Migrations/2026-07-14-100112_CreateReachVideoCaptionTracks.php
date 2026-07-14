<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoCaptionTracks extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_caption_tracks (
                id                  BIGSERIAL    PRIMARY KEY,
                uuid                UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                script_version_id   BIGINT       NOT NULL REFERENCES reach_video_script_versions(id) ON DELETE CASCADE,
                language            VARCHAR(16)  NOT NULL DEFAULT 'en',
                track_name          VARCHAR(255) NOT NULL,
                content             TEXT         NOT NULL,
                format              VARCHAR(16)  NOT NULL DEFAULT 'srt'
                                    CHECK (format IN ('srt','vtt')),
                is_default          BOOLEAN      NOT NULL DEFAULT false,
                remote_track_id     VARCHAR(255),
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                UNIQUE (script_version_id, language)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvct_version  ON reach_video_caption_tracks(script_version_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvct_lang     ON reach_video_caption_tracks(language)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_caption_tracks CASCADE');
    }
}

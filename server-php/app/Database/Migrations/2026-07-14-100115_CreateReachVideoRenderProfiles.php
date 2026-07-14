<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoRenderProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_render_profiles (
                id              BIGSERIAL    PRIMARY KEY,
                uuid            UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                tenant_id       BIGINT       NOT NULL,
                name            VARCHAR(255) NOT NULL,
                description     TEXT,
                resolution      VARCHAR(16)  NOT NULL DEFAULT '1920x1080',
                frame_rate      INT          NOT NULL DEFAULT 30,
                bitrate_kbps    INT          NOT NULL DEFAULT 4000,
                output_format   VARCHAR(16)  NOT NULL DEFAULT 'mp4'
                                CHECK (output_format IN ('mp4','webm')),
                extra_config    JSONB        NOT NULL DEFAULT '{}'::jsonb,
                is_default      BOOLEAN      NOT NULL DEFAULT false,
                is_active       BOOLEAN      NOT NULL DEFAULT true,
                created_by      BIGINT       REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvrp_tenant   ON reach_video_render_profiles(tenant_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvrp_active   ON reach_video_render_profiles(is_active)');
        $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS idx_rvrp_default ON reach_video_render_profiles(tenant_id) WHERE is_default = true');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_render_profiles CASCADE');
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoPublicationProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_publication_profiles (
                id                  BIGSERIAL    PRIMARY KEY,
                uuid                UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                project_id          BIGINT       NOT NULL REFERENCES reach_video_projects(id) ON DELETE CASCADE,
                tenant_id           BIGINT       NOT NULL,
                platform            VARCHAR(32)  NOT NULL DEFAULT 'youtube'
                                    CHECK (platform IN ('youtube')),
                title               VARCHAR(100),
                description         TEXT,
                tags                JSONB        NOT NULL DEFAULT '[]'::jsonb,
                category_id         VARCHAR(16),
                privacy_status      VARCHAR(16)  NOT NULL DEFAULT 'private'
                                    CHECK (privacy_status IN ('public','unlisted','private')),
                thumbnail_asset_id  BIGINT       REFERENCES reach_video_assets(id) ON DELETE SET NULL,
                extra_metadata      JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_by          BIGINT       REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                UNIQUE (project_id, platform)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvpp_project  ON reach_video_publication_profiles(project_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvpp_tenant   ON reach_video_publication_profiles(tenant_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_publication_profiles CASCADE');
    }
}

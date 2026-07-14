<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoAssets extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_assets (
                id              BIGSERIAL    PRIMARY KEY,
                uuid            UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                project_id      BIGINT       NOT NULL REFERENCES reach_video_projects(id) ON DELETE CASCADE,
                tenant_id       BIGINT       NOT NULL,
                asset_type      VARCHAR(32)  NOT NULL DEFAULT 'video'
                                CHECK (asset_type IN ('video','thumbnail','caption','source')),
                mime_type       VARCHAR(127) NOT NULL,
                file_extension  VARCHAR(16)  NOT NULL,
                storage_key     VARCHAR(512) NOT NULL UNIQUE,
                file_size_bytes BIGINT       NOT NULL DEFAULT 0,
                checksum_sha256 VARCHAR(64),
                status          VARCHAR(32)  NOT NULL DEFAULT 'uploaded'
                                CHECK (status IN ('uploading','uploaded','validated','rejected','deleted')),
                rejection_reason VARCHAR(255),
                created_by      BIGINT       REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rva_project   ON reach_video_assets(project_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rva_tenant    ON reach_video_assets(tenant_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rva_type      ON reach_video_assets(asset_type)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rva_status    ON reach_video_assets(status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_assets CASCADE');
    }
}

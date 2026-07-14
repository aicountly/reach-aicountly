<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoScriptVersions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_script_versions (
                id                      BIGSERIAL   PRIMARY KEY,
                uuid                    UUID        NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                script_id               BIGINT      NOT NULL REFERENCES reach_video_scripts(id) ON DELETE CASCADE,
                version_number          INT         NOT NULL,
                content_json            JSONB       NOT NULL,
                generation_artifact_id  BIGINT,
                validation_run_id       BIGINT,
                approved_by             BIGINT      REFERENCES reach_actors(id) ON DELETE SET NULL,
                approved_at             TIMESTAMPTZ,
                submitted_by            BIGINT      REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_by              BIGINT      REFERENCES reach_actors(id) ON DELETE SET NULL,
                is_current              BOOLEAN     NOT NULL DEFAULT false,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (script_id, version_number)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvsv_script    ON reach_video_script_versions(script_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvsv_current   ON reach_video_script_versions(script_id, is_current) WHERE is_current = true');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvsv_approved  ON reach_video_script_versions(approved_by)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_script_versions CASCADE');
    }
}

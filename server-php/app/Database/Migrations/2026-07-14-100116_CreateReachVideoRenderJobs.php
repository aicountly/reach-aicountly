<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoRenderJobs extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_render_jobs (
                id                  BIGSERIAL    PRIMARY KEY,
                uuid                UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                project_id          BIGINT       NOT NULL REFERENCES reach_video_projects(id) ON DELETE CASCADE,
                script_version_id   BIGINT       NOT NULL REFERENCES reach_video_script_versions(id) ON DELETE RESTRICT,
                render_profile_id   BIGINT       REFERENCES reach_video_render_profiles(id) ON DELETE SET NULL,
                provider            VARCHAR(64)  NOT NULL DEFAULT 'mock',
                idempotency_key     VARCHAR(128) NOT NULL UNIQUE,
                status              VARCHAR(32)  NOT NULL DEFAULT 'queued'
                                    CHECK (status IN (
                                        'queued','reserved','rendering','rendered',
                                        'failed','cancelled','dead_letter'
                                    )),
                attempt_count       INT          NOT NULL DEFAULT 0,
                max_attempts        INT          NOT NULL DEFAULT 3,
                output_asset_id     BIGINT       REFERENCES reach_video_assets(id) ON DELETE SET NULL,
                failure_class       VARCHAR(64),
                failure_message     TEXT,
                provider_job_id     VARCHAR(255),
                reserved_at         TIMESTAMPTZ,
                completed_at        TIMESTAMPTZ,
                available_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                created_by          BIGINT       REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvrj_project    ON reach_video_render_jobs(project_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvrj_status     ON reach_video_render_jobs(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvrj_available  ON reach_video_render_jobs(available_at) WHERE status = \'queued\'');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvrj_provider   ON reach_video_render_jobs(provider_job_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_render_jobs CASCADE');
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoProjects extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_projects (
                id                          BIGSERIAL    PRIMARY KEY,
                uuid                        UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                tenant_id                   BIGINT       NOT NULL,
                idea_id                     BIGINT       REFERENCES reach_video_ideas(id) ON DELETE SET NULL,
                title                       VARCHAR(500) NOT NULL,
                status                      VARCHAR(32)  NOT NULL DEFAULT 'draft'
                                            CHECK (status IN (
                                                'draft',
                                                'script_generating',
                                                'script_draft',
                                                'script_in_review',
                                                'script_approved',
                                                'render_queued',
                                                'rendering',
                                                'rendered',
                                                'publish_queued',
                                                'publishing',
                                                'published',
                                                'generation_failed',
                                                'validation_blocked',
                                                'changes_requested',
                                                'render_failed',
                                                'publish_failed',
                                                'cancelled',
                                                'withdrawn'
                                            )),
                approved_script_version_id  BIGINT,
                lock_version                INT          NOT NULL DEFAULT 0,
                created_by                  BIGINT       REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvp_tenant   ON reach_video_projects(tenant_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvp_status   ON reach_video_projects(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvp_idea     ON reach_video_projects(idea_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvp_created  ON reach_video_projects(created_at DESC)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_projects CASCADE');
    }
}

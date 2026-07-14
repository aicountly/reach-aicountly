<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoScripts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_scripts (
                id              BIGSERIAL   PRIMARY KEY,
                uuid            UUID        NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                project_id      BIGINT      NOT NULL REFERENCES reach_video_projects(id) ON DELETE CASCADE,
                workflow_status VARCHAR(32) NOT NULL DEFAULT 'draft'
                                CHECK (workflow_status IN (
                                    'draft','in_review','approved','rejected','changes_requested'
                                )),
                current_version INT         NOT NULL DEFAULT 0,
                created_by      BIGINT      REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (project_id)
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvs_project  ON reach_video_scripts(project_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvs_status   ON reach_video_scripts(workflow_status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_scripts CASCADE');
    }
}

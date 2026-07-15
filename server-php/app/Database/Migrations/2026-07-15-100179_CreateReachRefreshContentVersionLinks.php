<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshContentVersionLinks extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_content_version_links (
                id                          BIGSERIAL PRIMARY KEY,
                workflow_id                 BIGINT NOT NULL REFERENCES reach_refresh_workflows(id),
                content_version_id          BIGINT,
                blog_version_id             BIGINT,
                community_answer_version_id BIGINT,
                video_script_version_id     BIGINT,
                generation_artifact_id      BIGINT,
                version_status              VARCHAR(30) NOT NULL DEFAULT 'draft'
                    CHECK (version_status IN ('draft','in_review','approved','rejected','superseded')),
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX reach_refresh_content_vl_workflow ON reach_refresh_content_version_links (workflow_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_content_version_links CASCADE");
    }
}

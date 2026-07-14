<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoIdeaSources extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_idea_sources (
                id          BIGSERIAL   PRIMARY KEY,
                idea_id     BIGINT      NOT NULL REFERENCES reach_video_ideas(id) ON DELETE CASCADE,
                source_type VARCHAR(64) NOT NULL,
                source_ref  VARCHAR(500),
                title       VARCHAR(500),
                snippet     TEXT,
                relevance   NUMERIC(5,2),
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvis_idea   ON reach_video_idea_sources(idea_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvis_type   ON reach_video_idea_sources(source_type)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_idea_sources CASCADE');
    }
}

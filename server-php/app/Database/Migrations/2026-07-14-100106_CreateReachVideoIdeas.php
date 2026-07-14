<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoIdeas extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_ideas (
                id                      BIGSERIAL    PRIMARY KEY,
                uuid                    UUID         NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                tenant_id               BIGINT       NOT NULL,
                title                   VARCHAR(500) NOT NULL,
                summary                 TEXT,
                status                  VARCHAR(32)  NOT NULL DEFAULT 'draft'
                                        CHECK (status IN ('draft','ready','accepted','rejected','archived','converted')),
                score_total             SMALLINT,
                score_breakdown         JSONB,
                rationale               TEXT,
                source_type             VARCHAR(32),
                source_ref_id           BIGINT,
                generation_request_id   BIGINT,
                similarity_score        NUMERIC(5,2),
                duplicate_of_id         BIGINT       REFERENCES reach_video_ideas(id) ON DELETE SET NULL,
                created_by              BIGINT       REFERENCES reach_actors(id) ON DELETE SET NULL,
                created_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvi_tenant    ON reach_video_ideas(tenant_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvi_status    ON reach_video_ideas(status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvi_score     ON reach_video_ideas(score_total DESC NULLS LAST)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvi_created   ON reach_video_ideas(created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvi_source    ON reach_video_ideas(source_type, source_ref_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_ideas CASCADE');
    }
}

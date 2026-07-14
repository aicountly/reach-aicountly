<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachVideoRenderAttempts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_video_render_attempts (
                id              BIGSERIAL   PRIMARY KEY,
                render_job_id   BIGINT      NOT NULL REFERENCES reach_video_render_jobs(id) ON DELETE CASCADE,
                attempt_number  INT         NOT NULL DEFAULT 1,
                provider        VARCHAR(64) NOT NULL,
                provider_job_id VARCHAR(255),
                status          VARCHAR(32) NOT NULL DEFAULT 'started'
                                CHECK (status IN ('started','succeeded','failed','cancelled')),
                failure_class   VARCHAR(64),
                failure_message TEXT,
                started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                completed_at    TIMESTAMPTZ,
                receipt_raw     JSONB
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvra_job       ON reach_video_render_attempts(render_job_id)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rvra_attempt   ON reach_video_render_attempts(render_job_id, attempt_number)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_video_render_attempts CASCADE');
    }
}

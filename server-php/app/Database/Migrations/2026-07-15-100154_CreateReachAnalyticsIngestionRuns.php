<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAnalyticsIngestionRuns extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_analytics_ingestion_runs (
                id              BIGSERIAL PRIMARY KEY,
                uuid            UUID NOT NULL DEFAULT gen_random_uuid(),
                connection_id   BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE RESTRICT,
                stream_type     VARCHAR(40) NOT NULL,
                run_type        VARCHAR(20) NOT NULL DEFAULT 'incremental'
                                CHECK (run_type IN ('incremental','backfill','manual','reconcile')),
                status          VARCHAR(20) NOT NULL DEFAULT 'started'
                                CHECK (status IN ('started','completed','failed','partial','cancelled')),
                date_from       DATE,
                date_to         DATE,
                rows_ingested   INT NOT NULL DEFAULT 0,
                rows_skipped    INT NOT NULL DEFAULT 0,
                rows_failed     INT NOT NULL DEFAULT 0,
                job_id          BIGINT,
                started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                completed_at    TIMESTAMPTZ,
                error_message   TEXT,
                CONSTRAINT uq_ingestion_run_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_ingestion_runs_connection ON reach_analytics_ingestion_runs (connection_id, started_at DESC)");
        $this->db->query("CREATE INDEX idx_ingestion_runs_status ON reach_analytics_ingestion_runs (status)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_analytics_ingestion_runs CASCADE");
    }
}

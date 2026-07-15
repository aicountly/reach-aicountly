<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAnalyticsIngestionCursors extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_analytics_ingestion_cursors (
                id                      BIGSERIAL PRIMARY KEY,
                connection_id           BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE CASCADE,
                stream_type             VARCHAR(40) NOT NULL CHECK (stream_type IN ('search_metrics','content_metrics','sitemap_check')),
                last_ingested_date      DATE,
                backfill_from_date      DATE,
                backfill_days_remaining INT NOT NULL DEFAULT 0,
                cursor_state            JSONB,
                is_backfill_active      BOOLEAN NOT NULL DEFAULT FALSE,
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_ingestion_cursor UNIQUE (connection_id, stream_type)
            )
        ");
        $this->db->query("CREATE INDEX idx_ingestion_cursors_connection ON reach_analytics_ingestion_cursors (connection_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_analytics_ingestion_cursors CASCADE");
    }
}

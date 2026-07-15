<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachSearchMetricFacts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_search_metric_facts (
                id                      BIGSERIAL PRIMARY KEY,
                content_identity_id     BIGINT NOT NULL REFERENCES reach_content_identities(id) ON DELETE CASCADE,
                connection_id           BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE RESTRICT,
                ingestion_run_id        BIGINT,
                metric_date             DATE NOT NULL,
                query                   TEXT,
                page_url                TEXT NOT NULL,
                device                  VARCHAR(20) CHECK (device IN ('DESKTOP','MOBILE','TABLET','SMARTTV','UNKNOWN')),
                country                 CHAR(2),
                clicks                  INT NOT NULL DEFAULT 0,
                impressions             INT NOT NULL DEFAULT 0,
                ctr                     NUMERIC(8,6),
                avg_position            NUMERIC(8,2),
                provider_freshness_at   TIMESTAMPTZ,
                collected_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                is_revised              BOOLEAN NOT NULL DEFAULT FALSE,
                CONSTRAINT uq_search_metric_fact UNIQUE (content_identity_id, connection_id, metric_date, query, page_url, device, country)
            )
        ");
        $this->db->query("CREATE INDEX idx_search_facts_identity_date ON reach_search_metric_facts (content_identity_id, metric_date DESC)");
        $this->db->query("CREATE INDEX idx_search_facts_connection ON reach_search_metric_facts (connection_id, metric_date DESC)");
        $this->db->query("CREATE INDEX idx_search_facts_date ON reach_search_metric_facts (metric_date DESC)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_search_metric_facts CASCADE");
    }
}

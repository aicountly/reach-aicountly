<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachContentMetricFacts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_content_metric_facts (
                id                          BIGSERIAL PRIMARY KEY,
                content_identity_id         BIGINT NOT NULL REFERENCES reach_content_identities(id) ON DELETE CASCADE,
                connection_id               BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE RESTRICT,
                ingestion_run_id            BIGINT,
                metric_date                 DATE NOT NULL,
                source                      VARCHAR(60),
                medium                      VARCHAR(60),
                campaign_name               VARCHAR(120),
                sessions                    INT NOT NULL DEFAULT 0,
                users                       INT NOT NULL DEFAULT 0,
                new_users                   INT NOT NULL DEFAULT 0,
                engaged_sessions            INT NOT NULL DEFAULT 0,
                engagement_rate             NUMERIC(8,6),
                avg_engagement_time_secs    NUMERIC(10,2),
                entrances                   INT NOT NULL DEFAULT 0,
                page_views                  INT NOT NULL DEFAULT 0,
                scroll_depth_pct            NUMERIC(5,2),
                provider_freshness_at       TIMESTAMPTZ,
                collected_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                is_revised                  BOOLEAN NOT NULL DEFAULT FALSE,
                CONSTRAINT uq_content_metric_fact UNIQUE (content_identity_id, connection_id, metric_date, source, medium)
            )
        ");
        $this->db->query("CREATE INDEX idx_content_facts_identity_date ON reach_content_metric_facts (content_identity_id, metric_date DESC)");
        $this->db->query("CREATE INDEX idx_content_facts_connection ON reach_content_metric_facts (connection_id, metric_date DESC)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_content_metric_facts CASCADE");
    }
}

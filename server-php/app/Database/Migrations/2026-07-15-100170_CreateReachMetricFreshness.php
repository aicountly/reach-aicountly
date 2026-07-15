<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachMetricFreshness extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_metric_freshness (
                id                          BIGSERIAL PRIMARY KEY,
                connection_id               BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE CASCADE,
                stream_type                 VARCHAR(40) NOT NULL,
                last_successful_at          TIMESTAMPTZ,
                last_failed_at              TIMESTAMPTZ,
                staleness_threshold_hours   INT NOT NULL DEFAULT 26,
                freshness_state             VARCHAR(20) NOT NULL DEFAULT 'unknown'
                                            CHECK (freshness_state IN ('fresh','stale','unknown','no_data')),
                consecutive_failures        INT NOT NULL DEFAULT 0,
                alert_sent_at               TIMESTAMPTZ,
                updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_metric_freshness UNIQUE (connection_id, stream_type)
            )
        ");
        $this->db->query("CREATE INDEX idx_metric_freshness_state ON reach_metric_freshness (freshness_state)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_metric_freshness CASCADE");
    }
}

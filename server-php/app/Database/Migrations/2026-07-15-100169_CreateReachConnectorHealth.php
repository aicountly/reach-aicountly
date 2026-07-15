<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachConnectorHealth extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_connector_health (
                id              BIGSERIAL PRIMARY KEY,
                connection_id   BIGINT NOT NULL REFERENCES reach_analytics_connections(id) ON DELETE CASCADE,
                checked_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                status          VARCHAR(20) NOT NULL DEFAULT 'unknown'
                                CHECK (status IN ('healthy','degraded','failing','unknown')),
                latency_ms      INT,
                error_message   TEXT,
                http_status     INT,
                error_class     VARCHAR(20) CHECK (error_class IN ('Transient','RateLimit','AuthFailure','QuotaExceeded','Malformed','Permanent')),
                retry_after_at  TIMESTAMPTZ,
                metadata        JSONB
            )
        ");
        $this->db->query("CREATE INDEX idx_connector_health_connection ON reach_connector_health (connection_id, checked_at DESC)");
        $this->db->query("CREATE INDEX idx_connector_health_status ON reach_connector_health (status, checked_at DESC)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_connector_health CASCADE");
    }
}

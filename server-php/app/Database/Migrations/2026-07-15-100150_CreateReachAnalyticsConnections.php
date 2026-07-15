<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAnalyticsConnections extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_analytics_connections (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id               BIGINT NOT NULL,
                provider                VARCHAR(30) NOT NULL CHECK (provider IN ('gsc','ga4','bing','indexnow')),
                display_name            VARCHAR(120) NOT NULL,
                site_property           TEXT,
                property_id             VARCHAR(60),
                credential_reference    TEXT,
                enabled                 BOOLEAN NOT NULL DEFAULT FALSE,
                health_status           VARCHAR(20) NOT NULL DEFAULT 'unknown'
                                        CHECK (health_status IN ('healthy','degraded','failing','unknown')),
                last_health_check_at    TIMESTAMPTZ,
                last_successful_ingest  TIMESTAMPTZ,
                enabled_at              TIMESTAMPTZ,
                disabled_at             TIMESTAMPTZ,
                revoked_at              TIMESTAMPTZ,
                created_by              BIGINT,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_analytics_connection_uuid UNIQUE (uuid),
                CONSTRAINT uq_analytics_connection_tenant_provider UNIQUE (tenant_id, provider, site_property)
            )
        ");
        $this->db->query("CREATE INDEX idx_analytics_connections_tenant ON reach_analytics_connections (tenant_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_analytics_connections CASCADE");
    }
}

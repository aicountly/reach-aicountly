<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCompetitors extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_competitors (
                id                  BIGSERIAL PRIMARY KEY,
                uuid                UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id           BIGINT NOT NULL,
                name                VARCHAR(120) NOT NULL,
                legal_name          VARCHAR(200),
                website_domain      VARCHAR(120),
                category            VARCHAR(60),
                monitoring_enabled  BOOLEAN NOT NULL DEFAULT TRUE,
                monitoring_status   VARCHAR(20) NOT NULL DEFAULT 'active'
                                    CHECK (monitoring_status IN ('active','paused','archived')),
                effective_from      DATE,
                effective_to        DATE,
                notes               TEXT,
                created_by          BIGINT,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_competitor_uuid UNIQUE (uuid),
                CONSTRAINT uq_competitor_name UNIQUE (tenant_id, name)
            )
        ");
        $this->db->query("CREATE INDEX idx_competitors_tenant ON reach_competitors (tenant_id, monitoring_status)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_competitors CASCADE");
    }
}

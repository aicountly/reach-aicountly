<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAttributionCalculationVersions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_attribution_calculation_versions (
                id                  BIGSERIAL PRIMARY KEY,
                uuid                UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id           BIGINT NOT NULL,
                version_number      INT NOT NULL,
                calculated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                method              VARCHAR(20) NOT NULL DEFAULT 'last_touch'
                                    CHECK (method IN ('first_touch','last_touch')),
                period_from         DATE,
                period_to           DATE,
                total_conversions   INT NOT NULL DEFAULT 0,
                attributed_count    INT NOT NULL DEFAULT 0,
                unattributed_count  INT NOT NULL DEFAULT 0,
                calculation_params  JSONB,
                triggered_by        VARCHAR(20) NOT NULL DEFAULT 'job',
                CONSTRAINT uq_attribution_calc_uuid UNIQUE (uuid),
                CONSTRAINT uq_attribution_calc_version UNIQUE (tenant_id, version_number)
            )
        ");
        $this->db->query("CREATE INDEX idx_attribution_calc_tenant ON reach_attribution_calculation_versions (tenant_id, calculated_at DESC)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_attribution_calculation_versions CASCADE");
    }
}

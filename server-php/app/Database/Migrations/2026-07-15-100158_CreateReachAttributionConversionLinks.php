<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAttributionConversionLinks extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_attribution_conversion_links (
                id                          BIGSERIAL PRIMARY KEY,
                uuid                        UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id                   BIGINT NOT NULL,
                lead_id                     BIGINT,
                first_touchpoint_id         BIGINT REFERENCES reach_attribution_touchpoints(id) ON DELETE SET NULL,
                last_touchpoint_id          BIGINT REFERENCES reach_attribution_touchpoints(id) ON DELETE SET NULL,
                conversion_type             VARCHAR(40) NOT NULL DEFAULT 'lead'
                                            CHECK (conversion_type IN ('lead','signup','demo_request','contact','download','engage_push')),
                converted_at                TIMESTAMPTZ NOT NULL,
                matching_method             VARCHAR(40) NOT NULL DEFAULT 'last_touch'
                                            CHECK (matching_method IN ('first_touch','last_touch','manual','unattributed')),
                confidence_state            VARCHAR(20) NOT NULL DEFAULT 'inferred'
                                            CHECK (confidence_state IN ('confirmed','inferred','unattributed','corrected')),
                calculation_version_id      BIGINT,
                manual_correction_note      TEXT,
                corrected_by                BIGINT,
                corrected_at                TIMESTAMPTZ,
                created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_attribution_conversion_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_conversion_links_tenant ON reach_attribution_conversion_links (tenant_id, converted_at DESC)");
        $this->db->query("CREATE INDEX idx_conversion_links_lead ON reach_attribution_conversion_links (lead_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_attribution_conversion_links CASCADE");
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAttributionJourneyCalculations extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_attribution_journey_calculations (
                id                    BIGSERIAL PRIMARY KEY,
                tenant_id             BIGINT NOT NULL REFERENCES reach_actors(id),
                conversion_link_id    BIGINT NOT NULL REFERENCES reach_attribution_conversion_links(id),
                model_version_id      BIGINT NOT NULL REFERENCES reach_attribution_model_versions(id),
                ordered_touchpoint_ids JSONB NOT NULL,
                total_touchpoints     INT NOT NULL,
                identity_confidence   VARCHAR(20) NOT NULL
                    CHECK (identity_confidence IN ('high','medium','low','pseudonymous')),
                completeness_score    NUMERIC(4,3),
                limitations_note      TEXT NOT NULL,
                calculated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX reach_attribution_jc_tenant ON reach_attribution_journey_calculations (tenant_id)");
        $this->db->query("CREATE INDEX reach_attribution_jc_conversion ON reach_attribution_journey_calculations (conversion_link_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_attribution_journey_calculations CASCADE");
    }
}

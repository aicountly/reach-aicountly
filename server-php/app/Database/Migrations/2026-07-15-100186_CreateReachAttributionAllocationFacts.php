<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAttributionAllocationFacts extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_attribution_allocation_facts (
                id                      BIGSERIAL PRIMARY KEY,
                journey_calculation_id  BIGINT NOT NULL REFERENCES reach_attribution_journey_calculations(id),
                touchpoint_id           BIGINT NOT NULL REFERENCES reach_attribution_touchpoints(id),
                touch_position          INT NOT NULL,
                allocation_weight       NUMERIC(6,4) NOT NULL CHECK (allocation_weight >= 0 AND allocation_weight <= 1),
                model_name              VARCHAR(50) NOT NULL,
                model_version           INT NOT NULL,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX reach_attribution_af_journey ON reach_attribution_allocation_facts (journey_calculation_id)");
        $this->db->query("CREATE INDEX reach_attribution_af_touchpoint ON reach_attribution_allocation_facts (touchpoint_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_attribution_allocation_facts CASCADE");
    }
}

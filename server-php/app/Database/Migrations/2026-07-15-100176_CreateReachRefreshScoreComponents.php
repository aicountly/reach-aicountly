<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachRefreshScoreComponents extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_refresh_score_components (
                id                  BIGSERIAL PRIMARY KEY,
                recommendation_id   BIGINT NOT NULL REFERENCES reach_refresh_recommendations(id),
                factor              VARCHAR(80) NOT NULL,
                raw_value           NUMERIC(12,4),
                weight              NUMERIC(4,3) NOT NULL,
                contribution        NUMERIC(12,4) NOT NULL,
                evidence_source     VARCHAR(50),
                evidence_period     VARCHAR(30),
                scoring_version     VARCHAR(20) NOT NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query("CREATE INDEX reach_refresh_score_components_rec ON reach_refresh_score_components (recommendation_id)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_refresh_score_components CASCADE");
    }
}

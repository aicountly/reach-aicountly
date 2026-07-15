<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAttributionModelVersions extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_attribution_model_versions (
                id             BIGSERIAL PRIMARY KEY,
                model_id       BIGINT NOT NULL REFERENCES reach_attribution_models(id),
                version_number INT NOT NULL,
                formula        TEXT NOT NULL,
                weight_rules   JSONB NOT NULL,
                approved_by    BIGINT REFERENCES reach_actors(id),
                approved_at    TIMESTAMPTZ,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (model_id, version_number)
            )
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_attribution_model_versions CASCADE");
    }
}

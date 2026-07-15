<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachAttributionModels extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_attribution_models (
                id                   BIGSERIAL PRIMARY KEY,
                tenant_id            BIGINT NOT NULL REFERENCES reach_actors(id),
                model_name           VARCHAR(50) NOT NULL
                    CHECK (model_name IN ('equal_weight','position_based','time_decay')),
                description          TEXT,
                formula              TEXT NOT NULL,
                lookback_window_days INT NOT NULL,
                limitations          TEXT NOT NULL,
                is_active            BOOLEAN NOT NULL DEFAULT FALSE,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, model_name)
            )
        ");
        $this->db->query("CREATE INDEX reach_attribution_models_tenant ON reach_attribution_models (tenant_id, is_active)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_attribution_models CASCADE");
    }
}

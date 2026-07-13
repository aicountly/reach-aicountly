<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_model_routes and reach_ai_model_fallbacks.
 * Circular fallback prevention is enforced at application level.
 */
class CreateReachAiModelRoutes extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_model_routes (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid(),
                task_type               VARCHAR(64) NOT NULL,
                content_type            VARCHAR(64),
                risk_level              VARCHAR(16),
                market_id               BIGINT,
                language                VARCHAR(8),
                primary_model_id        BIGINT NOT NULL REFERENCES reach_ai_models(id),
                maximum_cost            NUMERIC(18,8),
                maximum_latency_seconds INT,
                enabled                 BOOLEAN NOT NULL DEFAULT TRUE,
                priority                INT NOT NULL DEFAULT 0,
                valid_from              TIMESTAMPTZ,
                valid_until             TIMESTAMPTZ,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at              TIMESTAMPTZ
            )
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_model_routes_uuid ON reach_ai_model_routes (uuid)");
        $this->db->query("CREATE INDEX idx_ai_model_routes_task ON reach_ai_model_routes (task_type, content_type) WHERE enabled = TRUE AND deleted_at IS NULL");
        $this->db->query("CREATE INDEX idx_ai_model_routes_priority ON reach_ai_model_routes (priority DESC) WHERE enabled = TRUE AND deleted_at IS NULL");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_model_fallbacks (
                id                       BIGSERIAL PRIMARY KEY,
                route_id                 BIGINT NOT NULL REFERENCES reach_ai_model_routes(id),
                source_model_id          BIGINT NOT NULL REFERENCES reach_ai_models(id),
                fallback_model_id        BIGINT NOT NULL REFERENCES reach_ai_models(id),
                fallback_order           INT NOT NULL DEFAULT 1,
                allowed_error_categories JSONB NOT NULL DEFAULT '[]',
                maximum_attempts         INT NOT NULL DEFAULT 2,
                enabled                  BOOLEAN NOT NULL DEFAULT TRUE,
                created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_model_fallbacks
            ADD CONSTRAINT reach_ai_model_fallbacks_no_self_chk
            CHECK (source_model_id <> fallback_model_id)
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_fallback_route_src_order ON reach_ai_model_fallbacks (route_id, source_model_id, fallback_order)");
        $this->db->query("CREATE INDEX idx_ai_fallback_route ON reach_ai_model_fallbacks (route_id) WHERE enabled = TRUE");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_model_fallbacks");
        $this->db->query("DROP TABLE IF EXISTS reach_ai_model_routes");
    }
}
